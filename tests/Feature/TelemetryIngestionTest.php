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

    public function test_out_of_range_is_alarm_at_once_then_normalizes(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t0 = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');

        // No hay estado amarillo: la primera lectura fuera de parámetro ya es alarma registrada.
        $this->ingestor->telemetry($gateway, $this->payload($t0->getTimestampMs(), ['effective_time' => 100]), $t0);
        $this->assertSame(VariableState::Alarma, $this->state($plant, 'effective_time')->state);
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);
        $event = AlarmEvent::sole();
        $this->assertTrue($event->started_at->equalTo($t0), 'el evento empieza cuando salió de parámetro');
        $this->assertNull($event->ended_at);
        $this->assertEqualsWithDelta(120, $event->setpoint_at_start, 0.001);

        // Sigue fuera: no abre otro evento y guarda el peor valor.
        $t1 = $t0->addMinutes(5);
        $this->ingestor->telemetry($gateway, $this->payload($t1->getTimestampMs(), ['effective_time' => 90]), $t1);
        $this->assertSame(1, AlarmEvent::count());
        $this->assertEqualsWithDelta(90, $event->fresh()->worst_value, 0.001);

        // Vuelve a parámetro → se cierra el evento.
        $t2 = $t0->addMinutes(17);
        $this->ingestor->telemetry($gateway, $this->payload($t2->getTimestampMs()), $t2);
        $event->refresh();
        $this->assertTrue($event->ended_at->equalTo($t2));
        $this->assertSame(17 * 60, $event->duration_s);
        $this->assertSame(VariableState::Ok, $this->state($plant, 'effective_time')->state);
        $this->assertSame(PlantStatus::Conforme, $plant->fresh()->status);
    }

    public function test_chlorine_is_present_from_its_configured_minimum(): void
    {
        [$plant, $gateway] = $this->makePlant(); // mínimo por defecto 0,10 ppm
        $t = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');
        $send = fn (int $min, float $value) => $this->ingestor->telemetry($gateway, $this->payload($t->addMinutes($min)->getTimestampMs(), ['chlorine' => $value]), $t->addMinutes($min));

        $send(0, 0.10);
        $this->assertSame(VariableState::Ok, $this->state($plant, 'chlorine')->state, 'en 0,10 hay presencia');

        $send(1, 0.09);
        $this->assertSame(VariableState::Alarma, $this->state($plant, 'chlorine')->state);
        $this->assertSame(1, AlarmEvent::count());

        $send(2, 0.75);
        $this->assertSame(VariableState::Ok, $this->state($plant, 'chlorine')->state);
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

    public function test_chlorine_dioxide_alarms_below_minimum_and_above_maximum(): void
    {
        [$plant, $gateway] = $this->makePlant(); // rango por defecto 0,15 a 0,30 ppm
        $plant->settingsOrDefault()->update(['disinfectant' => 'chlorine_dioxide']);
        $t0 = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');
        $send = fn (int $min, float $value) => $this->ingestor->telemetry($gateway, $this->payload($t0->addMinutes($min)->getTimestampMs(), ['chlorine_dioxide' => $value]), $t0->addMinutes($min));

        $send(0, 0.20);
        $this->assertSame(VariableState::Ok, $this->state($plant, 'chlorine_dioxide')->state);

        // Por alta: alarma inmediata, registrada contra el máximo.
        $send(1, 0.40);
        $this->assertSame(VariableState::Alarma, $this->state($plant, 'chlorine_dioxide')->state);
        $send(6, 0.55);
        $this->assertSame(VariableState::Alarma, $this->state($plant, 'chlorine_dioxide')->state);
        $high = AlarmEvent::sole();
        $this->assertEqualsWithDelta(0.30, (float) $high->setpoint_at_start, 0.0001);
        $this->assertEqualsWithDelta(0.55, (float) $high->worst_value, 0.0001);

        $send(7, 0.20);
        $this->assertNotNull($high->fresh()->ended_at);

        // Por baja: alarma registrada contra el mínimo.
        $send(8, 0.10);
        $send(14, 0.05);
        $low = AlarmEvent::latest('id')->first();
        $this->assertEqualsWithDelta(0.15, (float) $low->setpoint_at_start, 0.0001);
        $this->assertEqualsWithDelta(0.05, (float) $low->worst_value, 0.0001);
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);
    }

    public function test_shared_channel_is_stored_as_the_plant_disinfectant(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');
        $count = fn (string $key) => DB::table('readings')->where('variable_id', Variable::where('key', $key)->value('id'))->count();
        $send = fn (int $min, array $values) => $this->ingestor->telemetry($gateway, json_encode(['ts' => $t->addMinutes($min)->getTimestampMs(), 'values' => $values]), $t->addMinutes($min));

        // Planta con ozono: el canal se guarda como ozono y el dióxido se descarta.
        $send(0, ['disinfectant' => 0.60, 'chlorine_dioxide' => 0]);
        $this->assertSame(1, $count('ozone'));
        $this->assertSame(0, $count('chlorine_dioxide'));

        // El ozono también alarma por alta.
        $send(1, ['ozone' => 1.5]);
        $this->assertSame(VariableState::Alarma, $this->state($plant, 'ozone')->state);

        // Planta con dióxido: el mismo canal pasa a guardarse como dióxido.
        $plant->settingsOrDefault()->update(['disinfectant' => 'chlorine_dioxide']);
        $send(2, ['disinfectant' => 0.20, 'ozone' => 0]);
        $this->assertSame(2, $count('ozone'));
        $this->assertSame(1, $count('chlorine_dioxide'));
    }

    public function test_changing_disinfectant_clears_the_previous_one(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t = CarbonImmutable::now()->startOfSecond()->subHour();
        $this->ingestor->telemetry($gateway, $this->payload($t->getTimestampMs(), ['ozone' => 0.10]), $t);
        $this->ingestor->telemetry($gateway, $this->payload($t->addMinutes(6)->getTimestampMs(), ['ozone' => 0.10]), $t->addMinutes(6));
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);

        $this->actingAs($this->user(\App\Enums\Role::Admin))->put("/plantas/{$plant->code}/configuracion", [
            'setpoints' => ['temperature' => 60, 'alkaline' => 0.3, 'chlorine' => 0.1, 'chlorine_dioxide' => 0.15],
            'setpoints_high' => ['chlorine_dioxide' => 0.30],
            'disinfectant' => 'chlorine_dioxide',
            'cip_period_days' => 7,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(AlarmEvent::sole()->ended_at);
        $this->assertSame(0, PlantVariableState::where('plant_id', $plant->id)->where('variable_id', Variable::where('key', 'ozone')->value('id'))->count());
        $this->assertSame(PlantStatus::Conforme, $plant->fresh()->status);
    }

    public function test_conductivity_and_disinfectant_go_straight_to_alarm(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t0 = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');

        $this->ingestor->telemetry($gateway, $this->payload($t0->getTimestampMs(), ['ozone' => 0.45, 'alkaline' => 0.2]), $t0);

        $this->assertSame(VariableState::Alarma, $this->state($plant, 'ozone')->state);
        $this->assertSame(VariableState::Alarma, $this->state($plant, 'alkaline')->state);
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);
        $this->assertSame(2, AlarmEvent::count());
    }

    public function test_stopped_equipment_records_nothing_and_closes_its_alarms(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');
        $count = fn (string $key) => DB::table('readings')->where('variable_id', Variable::where('key', $key)->value('id'))->count();
        $send = fn (int $min, array $producing, array $values = []) => $this->ingestor->telemetry($gateway, json_encode([
            'ts' => $t->addMinutes($min)->getTimestampMs(),
            'producing' => $producing,
            'values' => array_merge(['ozone' => 0.58, 'temperature' => 63.0], $values),
        ]), $t->addMinutes($min));

        // Los dos equipos producen: se registra todo y la temperatura baja dispara la alarma.
        $send(0, ['lavadora' => true, 'llenadora' => true], ['temperature' => 20.0]);
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);
        $event = AlarmEvent::sole();

        // La lavadora para: se cierra su alarma y sus datos dejan de guardarse. La llenadora sigue.
        $send(10, ['lavadora' => false, 'llenadora' => true], ['temperature' => 20.0]);
        $this->assertTrue($event->fresh()->ended_at->equalTo($t->addMinutes(10)));
        $this->assertSame(1, $count('temperature'));
        $this->assertSame(2, $count('ozone'));
        $this->assertSame(PlantStatus::Conforme, $plant->fresh()->status);
        $this->assertSame(1, AlarmEvent::count(), 'detenida no dispara alarma');

        // Una muestra sin la señal usa el último estado conocido.
        $this->ingestor->telemetry($gateway, $this->payload($t->addMinutes(20)->getTimestampMs(), ['temperature' => 20.0]), $t->addMinutes(20));
        $this->assertSame(1, $count('temperature'));

        // Una muestra vieja repetida no vuelve a cambiar el estado.
        $send(0, ['lavadora' => true, 'llenadora' => true], ['temperature' => 20.0]);
        $this->assertFalse($plant->equipment()->where('type', 'lavadora')->sole()->producing);

        // La lavadora vuelve a producir: otra vez se registra y alarma.
        $send(30, ['lavadora' => true, 'llenadora' => true], ['temperature' => 20.0]);
        $this->assertSame(2, $count('temperature'));
        $this->assertSame(2, AlarmEvent::count());
    }

    public function test_stopped_llenadora_does_not_stop_the_lavadora_variables(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $t = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');

        $this->ingestor->telemetry($gateway, json_encode([
            'ts' => $t->getTimestampMs(),
            'producing' => ['lavadora' => true, 'llenadora' => false],
            'values' => ['ozone' => 0.10, 'temperature' => 63.0],
        ]), $t);

        $this->assertSame(0, AlarmEvent::count());
        $this->assertSame(1, DB::table('readings')->count());
        $this->assertSame(['ok', 'Llenadora'], [
            collect(app(\App\Services\Panel\PlantPresenter::class)->variables($plant))->firstWhere('key', 'ozone')['state'],
            collect(app(\App\Services\Panel\PlantPresenter::class)->variables($plant))->firstWhere('key', 'ozone')['stoppedEquipment'],
        ]);
    }

    public function test_exemys_sync_reads_on_off_channels_as_the_platform_sends_them(): void
    {
        config([
            'telemetry.exemys.unit_id' => 77,
            'telemetry.exemys.username' => 'u',
            'telemetry.exemys.password' => 'p',
            'telemetry.exemys.data_from' => null,
            'telemetry.exemys.io_map' => [1 => 'temperature', 2 => 'disinfectant'],
            'telemetry.exemys.cip_map' => [3 => 'lavadora.saneado'],
            'telemetry.exemys.production_map' => [4 => 'lavadora', 5 => 'llenadora'],
        ]);
        $this->seed(\Database\Seeders\VariableSeeder::class);

        $current = fn (string $saneado, string $lavadora) => ['unitID' => 77, 'unitDesc' => 'GRD', 'linkState' => 1, 'lastReport' => '00:00:10', 'ios' => [
            ['id' => 1, 'desc' => 'Temperatura', 'val' => '21.6', 'unit' => ''],
            ['id' => 2, 'desc' => 'Dioxido', 'val' => '0.20', 'unit' => ''],
            ['id' => 3, 'desc' => 'CIP LAV Saneado', 'val' => $saneado, 'unit' => ''],
            ['id' => 4, 'desc' => 'Lavadora', 'val' => $lavadora, 'unit' => ''],
            ['id' => 5, 'desc' => 'Llenadora', 'val' => 'ON', 'unit' => ''],
        ]];
        \Illuminate\Support\Facades\Http::fake([
            '*historyValues.php*' => ['unitID' => 77, 'unitDesc' => 'GRD', 'ios' => [], 'records' => []],
            '*currentValues.php*' => \Illuminate\Support\Facades\Http::sequence()
                ->push($current('OFF', 'OFF'))->push($current('ON', 'OFF')),
        ]);

        $this->artisan('exemys:sync')->assertSuccessful();
        $plant = Gateway::where('serial', 'EXEMYS-77')->sole()->plant;
        $this->assertSame(0, DB::table('readings')->where('variable_id', Variable::where('key', 'temperature')->value('id'))->count());
        $this->assertSame(1, DB::table('readings')->where('variable_id', Variable::where('key', 'ozone')->value('id'))->count());
        $this->assertFalse($plant->equipment()->where('type', 'lavadora')->sole()->producing);

        $this->travel(1)->minutes();
        $this->artisan('exemys:sync')->assertSuccessful();
        $this->assertSame('en_curso', CipCycle::sole()->result);
    }

    public function test_disconnection_closes_alarms_and_outage_data_opens_none(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $presence = app(\App\Services\Telemetry\GatewayPresence::class);
        $t = CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC');
        $count = fn () => DB::table('readings')->where('variable_id', Variable::where('key', 'temperature')->value('id'))->count();

        $this->ingestor->telemetry($gateway, $this->payload($t->getTimestampMs(), ['temperature' => 20.0]), $t);
        $event = AlarmEvent::sole();

        // Se corta: la alarma se cierra en ese momento y se conserva el último valor.
        $presence->offline($gateway->fresh(), $t->addMinutes(5));
        $this->assertTrue($event->fresh()->ended_at->equalTo($t->addMinutes(5)));
        $this->assertSame(VariableState::Ok, $this->state($plant, 'temperature')->state);
        $this->assertEqualsWithDelta(20.0, (float) $this->state($plant, 'temperature')->last_value, 0.001);

        // Al volver, lo medido durante el corte se guarda para los gráficos pero no abre alarmas.
        $this->ingestor->telemetry($gateway->fresh(), $this->payload($t->addMinutes(30)->getTimestampMs(), ['temperature' => 21.0]), $t->addMinutes(60));
        $this->assertSame(2, $count());
        $this->assertSame(1, AlarmEvent::count());

        // Con la conexión de vuelta, la siguiente lectura sí alarma.
        $this->ingestor->telemetry($gateway->fresh(), $this->payload($t->addMinutes(61)->getTimestampMs(), ['temperature' => 22.0]), $t->addMinutes(61));
        $this->assertSame(2, AlarmEvent::count());
    }

    public function test_exemys_history_of_a_disconnected_unit_does_not_flap_the_connection(): void
    {
        config([
            'telemetry.exemys.unit_id' => 77,
            'telemetry.exemys.username' => 'u',
            'telemetry.exemys.password' => 'p',
            'telemetry.exemys.data_from' => null,
            'telemetry.exemys.io_map' => [1 => 'temperature'],
            'telemetry.exemys.cip_map' => [],
            'telemetry.exemys.production_map' => [],
        ]);
        $this->seed(\Database\Seeders\VariableSeeder::class);
        $at = CarbonImmutable::now()->subMinutes(20)->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i:00');
        $ios = [['id' => 1, 'desc' => 'Temperatura', 'unit' => '']];

        \Illuminate\Support\Facades\Http::fake([
            '*historyValues.php*' => ['unitID' => 77, 'unitDesc' => 'GRD', 'ios' => $ios, 'records' => [['date' => $at, 'values' => '20.0']]],
            '*currentValues.php*' => ['unitID' => 77, 'unitDesc' => 'GRD', 'linkState' => 0, 'lastReport' => '00:20:00', 'ios' => [$ios[0] + ['val' => '20.0']]],
        ]);

        $gateway = Gateway::create([
            'plant_id' => \App\Models\Plant::create(['code' => 'exemys', 'name' => 'Exemys', 'location' => 'x'])->id,
            'serial' => 'EXEMYS-77', 'model' => 'Exemys', 'online' => true,
        ]);
        $this->artisan('exemys:sync')->assertSuccessful();
        $this->artisan('exemys:sync')->assertSuccessful();

        $this->assertFalse($gateway->fresh()->online);
        $this->assertSame(1, ConnectionEvent::count());
        $this->assertNull(ConnectionEvent::sole()->online_at);
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
            'telemetry.exemys.data_from' => null,
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

    public function test_chained_cycles_without_pause_finish_complete(): void
    {
        [, $gateway] = $this->makePlant();
        $cip = app(CipService::class);
        $t = CarbonImmutable::now()->startOfMinute()->subHours(6);
        $stages = CipCycle::STAGES;

        // Una muestra por etapa, 20 minutos cada una, en el orden de los canales: como Matías dejó el PLC.
        $sample = function (?int $active, int $minute) use ($cip, $gateway, $t, $stages) {
            $marks = [];
            foreach ($stages as $i => $stage) {
                $marks[] = ['equipment' => 'lavadora', 'stage' => $stage, 'on' => $i === $active];
            }
            $cip->signals($gateway, $marks, $t->addMinutes($minute));
        };

        $sample(null, 0);
        for ($step = 0; $step < 8; $step++) {
            $sample($step % 4, ($step + 1) * 20);
        }
        $sample(0, 180); // arranca el tercer ciclo, justo cuando baja el enjuague final

        $cycles = CipCycle::with('stages')->orderBy('started_at')->get();
        $this->assertSame(['finalizado', 'finalizado', CipService::RUNNING], $cycles->pluck('result')->all());
        $this->assertSame([20, 20, 20, 20], $cycles[0]->stages->pluck('duration_min')->all());
        $this->assertSame([20, 20, 20, 20], $cycles[1]->stages->pluck('duration_min')->all());
    }

    public function test_exemys_sync_maps_real_channels_and_skips_data_before_cutoff(): void
    {
        $this->seed(\Database\Seeders\VariableSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:30:00', 'UTC'));
        config([
            'telemetry.exemys.unit_id' => 77,
            'telemetry.exemys.username' => 'u',
            'telemetry.exemys.password' => 'p',
            'telemetry.exemys.data_from' => '2026-11-04 19:00:00',
            'telemetry.exemys.cip_map' => [],
        ]);

        $ios = [['id' => 53292, 'desc' => 'PV Controlador 1', 'unit' => ''], ['id' => 53294, 'desc' => 'SV Controlador 1', 'unit' => '']];
        \Illuminate\Support\Facades\Http::fake([
            '*historyValues.php*' => ['unitID' => 77, 'unitDesc' => 'GRD', 'ios' => $ios, 'records' => [['date' => '2026-11-05 10:00:00', 'values' => '12.3,21.4']]],
            '*currentValues.php*' => ['unitID' => 77, 'unitDesc' => 'GRD', 'linkState' => 1, 'lastReport' => '00:00:10', 'ios' => [
                ['id' => 53292, 'desc' => 'PV Controlador 1', 'val' => '12.4', 'unit' => ''],
                ['id' => 53294, 'desc' => 'SV Controlador 1', 'val' => '21.5', 'unit' => ''],
            ]],
        ]);

        $this->artisan('exemys:sync')->assertSuccessful();

        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => ! str_contains($request->url(), 'historyValues')
            || $request['since'] === '2026/11/04 19:00:00');

        $plant = Gateway::where('serial', 'EXEMYS-77')->sole()->plant;
        $this->assertEqualsWithDelta(12.4, (float) $this->state($plant, 'alkaline')->last_value, 0.0001);
        $this->assertEqualsWithDelta(21.5, (float) $this->state($plant, 'temperature')->last_value, 0.0001);
    }

    public function test_conductivity_migration_converts_stored_data_to_ms(): void
    {
        [$plant] = $this->makePlant();
        $alkaline = Variable::where('key', 'alkaline')->sole();
        $dioxide = Variable::where('key', 'chlorine_dioxide')->sole();
        DB::table('plant_setpoints')->insert([
            ['plant_id' => $plant->id, 'variable_id' => $alkaline->id, 'setpoint' => 300, 'setpoint_high' => null],
            ['plant_id' => $plant->id, 'variable_id' => $dioxide->id, 'setpoint' => 0.20, 'setpoint_high' => 0.80],
        ]);
        DB::table('readings')->insert([
            'plant_id' => $plant->id, 'variable_id' => $alkaline->id, 'value' => 360,
            'measured_at' => '2026-11-01 12:00:00', 'received_at' => '2026-11-01 12:00:00',
        ]);

        $migration = require database_path('migrations/2026_11_05_000100_conductivity_ms.php');
        $migration->up();

        $setpoint = fn (Variable $v) => DB::table('plant_setpoints')->where('variable_id', $v->id)->first();
        $this->assertEqualsWithDelta(0.3, (float) $setpoint($alkaline)->setpoint, 0.0001);
        $this->assertEqualsWithDelta(0.36, (float) DB::table('readings')->where('variable_id', $alkaline->id)->value('value'), 0.0001);
        $this->assertEqualsWithDelta(0.15, (float) $setpoint($dioxide)->setpoint, 0.0001);
        $this->assertEqualsWithDelta(0.30, (float) $setpoint($dioxide)->setpoint_high, 0.0001);
        $this->assertSame('mS/cm', $alkaline->fresh()->unit);
    }

    public function test_purge_simulated_only_touches_exemys_plant(): void
    {
        [$plant, $gateway] = $this->makePlant(serial: 'EXEMYS-77');
        [$other, $otherGateway] = $this->makePlant('Otra Planta', 'EM300-OTRA');
        config(['telemetry.exemys.unit_id' => 77]);
        $t = CarbonImmutable::now()->startOfSecond();
        $this->ingestor->telemetry($gateway, $this->payload($t->getTimestampMs()), $t);
        $this->ingestor->telemetry($otherGateway, $this->payload($t->getTimestampMs()), $t);
        $cip = app(CipService::class);
        foreach ([$gateway, $otherGateway] as $g) {
            $cip->signal($g, 'lavadora', 'saneado', false, $t->subMinutes(2));
            $cip->signal($g, 'lavadora', 'saneado', true, $t->subMinute());
        }

        $this->artisan('exemys:purge-simulated')
            ->expectsConfirmation("Se borran TODAS las lecturas, alarmas y ciclos CIP de \"{$plant->name}\". ¿Continuar?", 'yes')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('readings')->where('plant_id', $plant->id)->count());
        $this->assertNull($plant->fresh()->last_reading_at);
        $this->assertSame(5, DB::table('readings')->where('plant_id', $other->id)->count());
        $this->assertSame(1, CipCycle::count());
        $this->assertSame(1, DB::table('cip_stage_states')->count());
    }

    public function test_simulator_feeds_exemys_plant_with_plc_marks(): void
    {
        [$plant, $gateway] = $this->makePlant(serial: 'EXEMYS-77');
        $gateway->update(['model' => \App\Console\Commands\SyncExemys::GATEWAY_MODEL]);
        // 11:30 UTC arranca un ciclo de lavadora completo; el de las 12:00 es el que saltea la desinfección.
        $start = CarbonImmutable::parse('2026-11-03 11:30:00', 'UTC');

        for ($m = -1; $m <= 43; $m++) {
            $this->travelTo($start->addMinutes($m));
            $this->artisan('telemetry:simulate', ['--exemys' => true])->assertSuccessful();
        }

        $cycles = CipCycle::with('stages')->whereHas('equipment', fn ($q) => $q->where('type', 'lavadora'))->orderBy('started_at')->get();
        $this->assertSame(['finalizado', 'incompleto'], $cycles->pluck('result')->all());
        $this->assertSame([3, 3, 3, 3], $cycles[0]->stages->pluck('duration_min')->all());
        $this->assertGreaterThan(0, DB::table('readings')->where('plant_id', $plant->id)->count());
        $this->assertTrue($gateway->fresh()->online);
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
