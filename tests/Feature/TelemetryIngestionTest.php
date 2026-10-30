<?php

namespace Tests\Feature;

use App\Enums\PlantStatus;
use App\Enums\VariableState;
use App\Models\AlarmEvent;
use App\Models\CipCycle;
use App\Models\ConnectionEvent;
use App\Models\Gateway;
use App\Models\MqttInbox;
use App\Models\PlantVariableState;
use App\Models\Variable;
use App\Services\Telemetry\CipService;
use App\Services\Telemetry\PayloadParser;
use App\Services\Telemetry\TelemetryIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelemetryIngestionTest extends TestCase
{
    use RefreshDatabase;

    private TelemetryIngestor $ingestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestor = app(TelemetryIngestor::class);
    }

    private function state($plant, string $key): PlantVariableState
    {
        return PlantVariableState::where('plant_id', $plant->id)->where('variable_id', Variable::where('key', $key)->value('id'))->firstOrFail();
    }

    public function test_stores_readings_ignores_duplicates_and_builds_hourly_average(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t = CarbonImmutable::parse('2026-10-04 12:10:00', 'UTC');

        $this->assertSame(5, $this->ingestor->telemetry($gateway, $this->payload($t->getTimestampMs(), ['ozone' => 0.60]), $t));
        $this->assertSame(0, $this->ingestor->telemetry($gateway, $this->payload($t->getTimestampMs(), ['ozone' => 0.60]), $t), 'reenvío duplicado');
        $this->ingestor->telemetry($gateway, $this->payload($t->addMinutes(5)->getTimestampMs(), ['ozone' => 0.70]), $t->addMinutes(5));

        $this->assertSame(10, DB::table('readings')->count());
        $hourly = DB::table('readings_hourly')->where('variable_id', Variable::where('key', 'ozone')->value('id'))->first();
        $this->assertEqualsWithDelta(0.65, (float) $hourly->avg_value, 0.0001);
        $this->assertEqualsWithDelta(0.60, (float) $hourly->min_value, 0.0001);
        $this->assertEqualsWithDelta(0.70, (float) $hourly->max_value, 0.0001);
        $this->assertSame(2, (int) $hourly->samples);

        $plant->refresh();
        $this->assertSame(PlantStatus::Conforme, $plant->status);
        $this->assertTrue($gateway->fresh()->online);
    }

    public function test_out_of_range_goes_attention_then_alarm_then_normalizes(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t0 = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');

        $this->ingestor->telemetry($gateway, $this->payload($t0->getTimestampMs(), ['ozone' => 0.45]), $t0);
        $this->assertSame(VariableState::Atencion, $this->state($plant, 'ozone')->state);
        $this->assertSame(PlantStatus::Atencion, $plant->fresh()->status);
        $this->assertSame(0, AlarmEvent::count(), 'atención no genera evento');

        // Sigue fuera de parámetro 5 minutos (alarm_delay_s = 300 por defecto) → alarma.
        $t1 = $t0->addMinutes(5);
        $this->ingestor->telemetry($gateway, $this->payload($t1->getTimestampMs(), ['ozone' => 0.40]), $t1);
        $this->assertSame(VariableState::Alarma, $this->state($plant, 'ozone')->state);
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);
        $event = AlarmEvent::sole();
        $this->assertTrue($event->started_at->equalTo($t0), 'el evento empieza cuando salió de parámetro');
        $this->assertNull($event->ended_at);
        $this->assertEqualsWithDelta(0.40, $event->worst_value, 0.001);
        $this->assertEqualsWithDelta(0.50, $event->setpoint_at_start, 0.001);

        // Vuelve a parámetro → se cierra el evento.
        $t2 = $t0->addMinutes(17);
        $this->ingestor->telemetry($gateway, $this->payload($t2->getTimestampMs()), $t2);
        $event->refresh();
        $this->assertTrue($event->ended_at->equalTo($t2));
        $this->assertSame(17 * 60, $event->duration_s);
        $this->assertSame(VariableState::Ok, $this->state($plant, 'ozone')->state);
        $this->assertSame(PlantStatus::Conforme, $plant->fresh()->status);
    }

    public function test_temperature_goes_straight_to_alarm(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t0 = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');

        $this->ingestor->telemetry($gateway, $this->payload($t0->getTimestampMs(), ['temperature' => 57.0]), $t0);
        $this->assertSame(VariableState::Alarma, $this->state($plant, 'temperature')->state);
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);
        $this->assertTrue(AlarmEvent::sole()->started_at->equalTo($t0));
    }

    public function test_short_deviation_never_becomes_an_event(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t0 = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');
        $this->ingestor->telemetry($gateway, $this->payload($t0->getTimestampMs(), ['ozone' => 0.45]), $t0);
        $this->ingestor->telemetry($gateway, $this->payload($t0->addMinutes(2)->getTimestampMs()), $t0->addMinutes(2));

        $this->assertSame(0, AlarmEvent::count());
        $this->assertSame(PlantStatus::Conforme, $plant->fresh()->status);
    }

    public function test_late_backfill_is_stored_but_does_not_rewind_state(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');
        $this->ingestor->telemetry($gateway, $this->payload($t->getTimestampMs()), $t);
        // Llega tarde una lectura vieja fuera de parámetro.
        $this->ingestor->telemetry($gateway, $this->payload($t->subMinutes(30)->getTimestampMs(), ['ozone' => 0.20]), $t);

        $this->assertSame(10, DB::table('readings')->count());
        $this->assertSame(VariableState::Ok, $this->state($plant, 'ozone')->state);
    }

    public function test_batch_after_outage_is_processed_in_order(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t0 = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');
        $batch = ['records' => [
            ['ts' => $t0->addMinutes(10)->getTimestampMs(), 'temperature' => 63],
            ['ts' => $t0->getTimestampMs(), 'temperature' => 50],
            ['ts' => $t0->addMinutes(6)->getTimestampMs(), 'temperature' => 51],
        ]];
        $this->ingestor->telemetry($gateway, json_encode($batch), $t0->addMinutes(11));

        $event = AlarmEvent::sole();
        $this->assertTrue($event->started_at->equalTo($t0));
        $this->assertTrue($event->ended_at->equalTo($t0->addMinutes(10)));
        $this->assertEqualsWithDelta(50, $event->worst_value, 0.001);
    }

    public function test_offline_detection_and_recovery(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t0 = CarbonImmutable::now()->subMinutes(30)->startOfSecond();
        $this->ingestor->telemetry($gateway, $this->payload($t0->getTimestampMs()), $t0);

        $this->artisan('telemetry:evaluate')->assertSuccessful();
        $this->assertFalse($gateway->fresh()->online);
        $this->assertSame(PlantStatus::SinConexion, $plant->fresh()->status);
        $this->assertSame(1, ConnectionEvent::whereNull('online_at')->count());

        $now = CarbonImmutable::now()->startOfSecond();
        $this->ingestor->telemetry($gateway->fresh(), $this->payload($now->getTimestampMs()), $now);
        $this->assertTrue($gateway->fresh()->online);
        $this->assertSame(PlantStatus::Conforme, $plant->fresh()->status);
        $this->assertNotNull(ConnectionEvent::sole()->online_at);
    }

    public function test_cip_cycles_drive_due_dates_and_plant_status(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $now = CarbonImmutable::now()->startOfSecond();
        $this->ingestor->telemetry($gateway, $this->payload($now->getTimestampMs()), $now);

        $cycle = fn (string $id, string $equipment, CarbonImmutable $at, array $stages) => json_encode([
            'cycle_id' => $id, 'equipment' => $equipment, 'finished_at' => $at->getTimestampMs(), 'stages' => $stages,
        ]);
        $full = ['saneado' => 15, 'enjuague' => 10, 'desinfeccion' => 20, 'enjuague_final' => 8];

        $this->ingestor->cipCycle($gateway, $cycle('L1', 'lavadora', $now->subDays(2), $full), $now);
        $this->ingestor->cipCycle($gateway, $cycle('F1', 'llenadora', $now->subDays(6)->subHours(10), $full), $now);
        $this->assertSame(PlantStatus::Atencion, $plant->fresh()->status, 'llenadora próxima a vencer');

        // Un ciclo incompleto no renueva la vigencia.
        $this->ingestor->cipCycle($gateway, $cycle('F2', 'llenadora', $now->subHour(), array_merge($full, ['desinfeccion' => null])), $now);
        $incomplete = CipCycle::where('plc_cycle_id', 'F2')->sole();
        $this->assertSame('incompleto', $incomplete->result);
        $this->assertSame(33, $incomplete->total_min);
        $this->assertSame(PlantStatus::Atencion, $plant->fresh()->status);

        // Reenvío del mismo ciclo: no se duplica.
        $this->ingestor->cipCycle($gateway, $cycle('F2', 'llenadora', $now->subHour(), $full), $now);
        $this->assertSame(1, CipCycle::where('plc_cycle_id', 'F2')->count());

        // Completo → vuelve a conforme.
        $this->ingestor->cipCycle($gateway, $cycle('F3', 'llenadora', $now, $full), $now);
        $this->assertSame(PlantStatus::Conforme, $plant->fresh()->status);

        // Vencido (8 días sin CIP de lavadora) → alarma.
        $this->travelTo($now->addDays(6));
        $this->artisan('telemetry:evaluate');
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);
    }

    public function test_plc_stage_marks_build_cip_cycles(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $cip = app(CipService::class);
        $t = CarbonImmutable::now()->startOfMinute()->subHour();
        $mark = fn (string $stage, int $on, int $minute, string $equipment = 'lavadora') => $cip->signal($gateway, $equipment, $stage, (bool) $on, $t->addMinutes($minute));

        // Primera muestra de cada marca: solo toma el estado.
        foreach (CipCycle::STAGES as $stage) {
            $this->assertFalse($mark($stage, 0, 0));
        }

        $mark('saneado', 1, 1);
        $this->assertSame(CipService::RUNNING, CipCycle::sole()->result);
        $this->assertFalse($mark('saneado', 1, 2), 'mismo estado');
        $this->assertFalse($mark('saneado', 0, 1), 'muestra vieja');

        $mark('saneado', 0, 4);
        $mark('enjuague', 1, 4);
        $mark('enjuague', 0, 7);
        $mark('desinfeccion', 1, 7);
        $mark('desinfeccion', 0, 10);
        $mark('enjuague_final', 1, 10);
        $mark('enjuague_final', 0, 13);

        $cycle = CipCycle::with('stages')->sole();
        $this->assertSame('finalizado', $cycle->result);
        $this->assertSame(12, $cycle->total_min);
        $this->assertSame([3, 3, 3, 3], $cycle->stages->pluck('duration_min')->all());
        $this->assertTrue($t->addMinutes(13)->equalTo($cycle->finished_at));

        // Sin desinfección → incompleto; un saneado nuevo con un ciclo abierto lo cierra incompleto.
        $mark('saneado', 1, 20);
        $mark('saneado', 0, 23);
        $mark('enjuague', 1, 23);
        $mark('enjuague', 0, 26);
        $mark('saneado', 1, 30);
        $previous = CipCycle::where('started_at', $t->addMinutes(20))->sole();
        $this->assertSame('incompleto', $previous->result);
        $this->assertSame(6, $previous->total_min);
        $this->assertSame(CipService::RUNNING, CipCycle::latest('id')->first()->result);

        $mark('saneado', 0, 33);
        $this->assertSame(0, $cip->closeStale($t->addMinutes(60)), 'todavía no pasaron 2 h');
        $this->assertSame(1, $cip->closeStale($t->addMinutes(33 + 121)));
        $this->assertSame(0, CipCycle::where('result', CipService::RUNNING)->count());
        $this->assertSame(1, CipCycle::where('result', 'finalizado')->count());
    }

    public function test_exemys_sync_reads_plc_stage_marks(): void
    {
        $this->seed(\Database\Seeders\VariableSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 13:30:00', 'UTC'));
        config([
            'telemetry.exemys.unit_id' => 77,
            'telemetry.exemys.username' => 'u',
            'telemetry.exemys.password' => 'p',
            'telemetry.exemys.io_map' => [],
            'telemetry.exemys.cip_map' => [1 => 'lavadora.saneado', 2 => 'lavadora.enjuague', 3 => 'lavadora.desinfeccion', 4 => 'lavadora.enjuague_final'],
        ]);

        // Un registro por minuto desde las 10:00 (hora de Exemys), 3 minutos por etapa.
        $records = [];
        for ($m = 0; $m <= 14; $m++) {
            $active = $m >= 1 && $m < 13 ? intdiv($m - 1, 3) : null;
            $values = array_map(fn ($i) => $i === $active ? '1' : '0', range(0, 3));
            $records[] = ['date' => sprintf('2026-10-30 10:%02d:00', $m), 'values' => implode(',', $values)];
        }
        $ios = array_map(fn ($id) => ['id' => $id, 'desc' => "I{$id}", 'unit' => ''], [1, 2, 3, 4]);
        \Illuminate\Support\Facades\Http::fake([
            '*historyValues.php*' => ['unitID' => 77, 'unitDesc' => 'GRD', 'ios' => $ios, 'records' => $records],
            '*currentValues.php*' => ['unitID' => 77, 'unitDesc' => 'GRD', 'linkState' => 1, 'lastReport' => '00:00:10',
                'ios' => array_map(fn ($io) => $io + ['val' => '0'], $ios)],
        ]);

        $this->artisan('exemys:sync')->assertSuccessful();

        $cycle = CipCycle::with('stages')->sole();
        $this->assertSame('finalizado', $cycle->result);
        $this->assertSame([3, 3, 3, 3], $cycle->stages->pluck('duration_min')->all());
        $this->assertSame('2026-10-30 13:13:00', $cycle->finished_at->utc()->format('Y-m-d H:i:s'));

        // La corrida siguiente vuelve a traer la misma hora: no duplica nada.
        $this->artisan('exemys:sync')->assertSuccessful();
        $this->assertSame(1, CipCycle::count());
    }

    public function test_mqtt_messages_are_logged_and_routed_by_topic(): void
    {
        [$plant, $gateway] = $this->makePlant(serial: 'EM300-0099');
        $t = CarbonImmutable::now()->startOfSecond();

        $ok = $this->ingestor->handleMqtt('ivess/EM300-0099/telemetry', $this->payload($t->getTimestampMs()), $t);
        $this->assertNotNull($ok->processed_at);
        $this->assertNull($ok->error);

        $unknown = $this->ingestor->handleMqtt('ivess/NO-EXISTE/telemetry', $this->payload($t->getTimestampMs()), $t);
        $this->assertNull($unknown->processed_at);
        $this->assertStringContainsString('Gateway no registrado', $unknown->error);

        $bad = $this->ingestor->handleMqtt('ivess/EM300-0099/telemetry', 'no es json', $t);
        $this->assertNotNull($bad->error);

        $this->ingestor->handleMqtt('ivess/EM300-0099/status', 'offline', $t);
        $this->assertFalse($gateway->fresh()->online);
        $this->assertSame(PlantStatus::SinConexion, $plant->fresh()->status);

        // Se da de alta el gateway faltante y se reprocesa.
        Gateway::create(['plant_id' => $plant->id, 'serial' => 'NO-EXISTE']);
        $this->artisan('telemetry:reprocess')->assertSuccessful();
        $this->assertNotNull($unknown->fresh()->processed_at);
        $this->assertSame(1, MqttInbox::whereNull('processed_at')->count(), 'solo queda el JSON inválido');
    }

    public function test_payload_parser_accepts_flat_iso_and_comma_decimals(): void
    {
        $parser = new PayloadParser;
        $received = CarbonImmutable::parse('2026-10-04 15:00:00', 'UTC');

        $records = $parser->telemetry('{"ts":"2026-10-04 11:59:30","ozone":"0,55","chlorine":true,"serial":"x"}', $received);
        $this->assertCount(1, $records);
        $this->assertSame('2026-10-04 14:59:30', $records[0]['measured_at']->format('Y-m-d H:i:s'), 'hora local AR → UTC');
        $this->assertSame(['ozone' => 0.55, 'chlorine' => 1.0], $records[0]['values']);

        // Segundos epoch y reloj adelantado.
        $this->assertSame($received->getTimestamp() - 60, $parser->telemetry(['ts' => $received->getTimestamp() - 60, 'ozone' => 1], $received)[0]['measured_at']->getTimestamp());
        $future = $parser->telemetry(['ts' => $received->addDay()->getTimestampMs(), 'ozone' => 1], $received);
        $this->assertTrue($future[0]['measured_at']->equalTo($received));
    }

    public function test_http_ingest_requires_gateway_token(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $gateway->update(['api_token_hash' => Gateway::hashToken('token-secreto')]);
        $body = $this->payload(CarbonImmutable::now()->getTimestampMs());

        $this->call('POST', '/api/ingest/telemetry', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
            ->assertStatus(401);

        $this->call('POST', '/api/ingest/telemetry', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer token-secreto',
        ], $body)->assertOk()->assertJson(['ok' => true, 'stored' => 5]);

        $this->call('POST', '/api/ingest/cip', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer token-secreto',
        ], json_encode(['equipment' => 'tanque']))->assertStatus(422);
    }
}
