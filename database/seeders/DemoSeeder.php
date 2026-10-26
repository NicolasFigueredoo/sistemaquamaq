<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\VariableState;
use App\Models\AlarmEvent;
use App\Models\ConnectionEvent;
use App\Models\Equipment;
use App\Models\Gateway;
use App\Models\Plant;
use App\Models\PlantSetpoint;
use App\Models\PlantVariableState;
use App\Models\User;
use App\Models\Variable;
use App\Services\Telemetry\PlantStatusService;
use App\Support\DemoSignals;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de demostración: 20 plantas ilustrativas, 30 días de historial horario,
 * eventos de alarma, 13 meses de ciclos CIP y usuarios de prueba.
 * NO correr en producción.
 */
class DemoSeeder extends Seeder
{
    /** [nombre, localidad, escenario] — escenario: ok | warning | offline | alarm */
    public const PLANTS = [
        ['Jauregui S.A.C.I. y A.', 'Luján', 'ok'], ['Costa S.A.', 'San Luis', 'ok'], ['Rosmino y Cía. S.A.', 'AMBA Norte', 'ok'],
        ['La Nicoleña', 'San Nicolás', 'ok'], ['Indusoda S.A.', 'Berazategui', 'ok'], ['Del Este S.A.', 'Mendoza', 'ok'],
        ['Coopagua Ltda.', 'CABA', 'ok'], ['Reggieri S.A.', 'Avellaneda', 'ok'], ['Soda Roca', 'GBA Sur', 'ok'],
        ['Mar del Plata Soda S.A.', 'Mar del Plata', 'ok'], ['Dorita S.A.', 'Posadas', 'ok'], ['Ladder S.A.', 'San Juan', 'ok'],
        ['Salvador Marinaro e Hijo S.R.L.', 'Salta', 'ok'],
        ['El Jumillano S.A.', 'AMBA / La Plata', 'warning'], ['Bocanera S.A.', 'Tucumán', 'warning'], ['La Primera de Tandil S.C.', 'Tandil', 'warning'],
        ['2 Vascos S.R.L.', 'Concordia', 'offline'], ['Rubén Omar Meder', 'Viedma', 'offline'],
        ['Industrial Gaseosa S.R.L.', 'Corrientes', 'alarm'], ['Comercial Quince S.A.', 'AMBA Sur', 'alarm'],
    ];

    private const CHARTED = ['ozone', 'temperature', 'alkaline'];

    public function run(): void
    {
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $variables = Variable::catalog();

        $admin = User::updateOrCreate(['username' => 'admin'], ['name' => 'Administrador IVESS', 'password' => 'IVESS40', 'role' => Role::Admin]);
        User::updateOrCreate(['username' => 'auditor'], ['name' => 'Auditoría IVESS', 'password' => 'Auditor40', 'role' => Role::Auditor]);
        $asociado = User::updateOrCreate(['username' => 'jumillano'], ['name' => 'El Jumillano S.A.', 'password' => 'Jumillano40', 'role' => Role::Asociado]);

        // Si se vuelve a correr, borra las plantas de demo que ya no están en la lista
        // (no toca las que reciben datos reales, como la de Exemys).
        $codes = array_map(fn ($p) => Str::slug($p[0]), self::PLANTS);
        Plant::whereNotIn('code', $codes)
            ->whereDoesntHave('gateways', fn ($q) => $q->where('model', '!=', 'EM300'))
            ->get()->each->delete();
        Gateway::where('model', 'EM300')
            ->whereNotIn('serial', array_map(fn ($i) => sprintf('EM300-%04d', $i + 1), array_keys(self::PLANTS)))
            ->delete();

        foreach (self::PLANTS as $index => [$name, $location, $scenario]) {
            $this->seedPlant($index, $name, $location, $scenario, $now, $variables);
        }

        $asociado->plants()->sync([Plant::where('code', Str::slug('El Jumillano S.A.'))->value('id')]);

        $status = app(PlantStatusService::class);
        Plant::all()->each(fn (Plant $p) => $status->refresh($p, $now));

        $this->command?->info('Demo lista: '.count(self::PLANTS).' plantas. Usuarios: admin/IVESS40 · auditor/Auditor40 · jumillano/Jumillano40');
    }

    private function seedPlant(int $index, string $name, string $location, string $scenario, CarbonImmutable $now, $variables): void
    {
        $phase = $index * 37;
        $plant = Plant::updateOrCreate(['code' => Str::slug($name)], ['name' => $name, 'location' => $location, 'active' => true]);
        $plant->settingsOrDefault();

        // Limpieza por si se vuelve a correr el seeder.
        DB::table('readings')->where('plant_id', $plant->id)->delete();
        DB::table('readings_hourly')->where('plant_id', $plant->id)->delete();
        AlarmEvent::where('plant_id', $plant->id)->delete();
        PlantVariableState::where('plant_id', $plant->id)->delete();

        foreach ($variables as $v) {
            PlantSetpoint::updateOrCreate(['plant_id' => $plant->id, 'variable_id' => $v->id], ['setpoint' => $v->default_setpoint, 'alarm_delay_s' => 300]);
        }

        $lastSeen = match ($scenario) {
            'offline' => $now->subHours(2)->subMinutes(47 + $index),
            default => $now->subSeconds(8 + $index % 20),
        };

        $gateway = Gateway::updateOrCreate(
            ['serial' => sprintf('EM300-%04d', $index + 1)],
            ['plant_id' => $plant->id, 'model' => 'EM300', 'firmware' => '1.0.4', 'mqtt_username' => sprintf('EM300-%04d', $index + 1),
                'online' => $scenario !== 'offline', 'last_seen_at' => $lastSeen],
        );
        ConnectionEvent::where('gateway_id', $gateway->id)->delete();
        if ($scenario === 'offline') {
            ConnectionEvent::create(['gateway_id' => $gateway->id, 'offline_at' => $lastSeen]);
        }

        // ── Historial horario de 30 días ─────────────────────────────────
        $points = 30 * 24;
        $startHour = $now->startOfHour()->subHours($points - 1);
        $endIndex = $scenario === 'offline' ? $points - 4 : $points - 1;
        $tStart = $startHour->getTimestamp() / 3600;

        // Variable con desvío para atención / alarma (misma elección que la maqueta).
        $selector = array_sum(array_map('ord', mb_str_split($name))) % 3;
        $deviated = ['ozone', 'temperature', 'alkaline'][$selector];
        $deviation = [
            'warning' => ['ozone' => 0.06, 'temperature' => 3, 'alkaline' => 37.5],
            'alarm' => ['ozone' => 0.14, 'temperature' => 8, 'alkaline' => 90],
        ];

        $hourly = [];
        $series = [];
        foreach (self::CHARTED as $key) {
            $v = $variables[$key];
            for ($i = 0; $i <= $endIndex; $i++) {
                $value = DemoSignals::value($key, $tStart + $i, $phase);
                if ($i === $endIndex) {
                    $value = $this->finalValue($key, $value, $v->default_setpoint, $scenario, $deviated, $deviation);
                }
                $value = round(max($v->chart_min, min($v->chart_max, $value)), 4);
                $series[$key][$i] = $value;
                $hourly[] = [
                    'plant_id' => $plant->id, 'variable_id' => $v->id, 'hour' => $startHour->addHours($i)->format('Y-m-d H:i:s'),
                    'avg_value' => $value, 'min_value' => $value, 'max_value' => $value, 'samples' => 360,
                ];
            }
        }
        foreach (array_chunk($hourly, 500) as $chunk) {
            DB::table('readings_hourly')->insert($chunk);
        }

        // ── Eventos de alarma: cada tramo bajo el setpoint dentro del historial ──
        foreach (self::CHARTED as $key) {
            $v = $variables[$key];
            $runStart = null;
            $worst = null;
            for ($i = 0; $i < $endIndex; $i++) {
                $below = $series[$key][$i] < $v->default_setpoint;
                if ($below) {
                    $runStart ??= $i;
                    $worst = $worst === null ? $series[$key][$i] : min($worst, $series[$key][$i]);
                }
                if ((! $below || $i === $endIndex - 1) && $runStart !== null) {
                    $length = ($below ? $i + 1 : $i) - $runStart;
                    $offset = ($runStart * 7 + $phase) % 35;
                    $started = $startHour->addHours($runStart)->subMinutes(30)->addMinutes($offset);
                    $duration = max(11, $length * 60 - 30 + (($runStart + $phase) % 14));
                    AlarmEvent::create([
                        'plant_id' => $plant->id, 'variable_id' => $v->id, 'started_at' => $started,
                        'ended_at' => $started->addMinutes($duration), 'duration_s' => $duration * 60,
                        'setpoint_at_start' => $v->default_setpoint, 'worst_value' => $worst,
                    ]);
                    $runStart = null;
                    $worst = null;
                }
            }
        }

        // ── Estado actual de cada variable + lecturas crudas de la última hora ──
        $lastAt = $scenario === 'offline' ? $lastSeen : $now->subSeconds(12 + $index % 30);
        $raw = [];
        foreach ($variables as $v) {
            $value = in_array($v->key, self::CHARTED, true)
                ? $series[$v->key][$endIndex]
                : DemoSignals::value($v->key, $lastAt->getTimestamp() / 3600, $phase);

            $state = VariableState::Ok;
            $outSince = null;
            $eventId = null;
            if ($v->key === $deviated && in_array($scenario, ['warning', 'alarm'], true)) {
                $state = $scenario === 'alarm' ? VariableState::Alarma : VariableState::Atencion;
                $outSince = $scenario === 'alarm' ? $lastAt->subMinutes(14 + $index % 9) : $lastAt->subSeconds(95);
                if ($scenario === 'alarm') {
                    $eventId = AlarmEvent::create([
                        'plant_id' => $plant->id, 'variable_id' => $v->id, 'started_at' => $outSince,
                        'setpoint_at_start' => $v->default_setpoint, 'worst_value' => $value,
                    ])->id;
                }
            }

            PlantVariableState::create([
                'plant_id' => $plant->id, 'variable_id' => $v->id, 'state' => $state, 'last_value' => $value,
                'last_measured_at' => $lastAt, 'out_since' => $outSince, 'worst_value' => $outSince ? $value : null,
                'open_alarm_event_id' => $eventId,
            ]);

            for ($m = 55; $m >= 0; $m -= 5) {
                $at = $lastAt->subMinutes($m);
                $raw[] = [
                    'plant_id' => $plant->id, 'variable_id' => $v->id, 'gateway_id' => $gateway->id,
                    'value' => $m === 0 ? $value : round(DemoSignals::value($v->key, $at->getTimestamp() / 3600, $phase, false), 4),
                    'measured_at' => $at->format('Y-m-d H:i:s'), 'received_at' => $at->addSecond()->format('Y-m-d H:i:s'),
                ];
            }
        }
        DB::table('readings')->insert($raw);

        $plant->last_reading_at = $lastAt;
        $plant->save();

        $this->seedCip($plant, $index, $scenario, $now);
    }

    private function finalValue(string $key, float $value, float $setpoint, string $scenario, string $deviated, array $deviation): float
    {
        if ($key === $deviated && isset($deviation[$scenario])) {
            return $setpoint - $deviation[$scenario][$key];
        }
        // Las variables sin desvío terminan dentro de parámetro.
        $margin = ['ozone' => 0.04, 'temperature' => 1.5, 'alkaline' => 45][$key];

        return max($value, $setpoint + $margin);
    }

    private function seedCip(Plant $plant, int $index, string $scenario, CarbonImmutable $now): void
    {
        $plan = [
            'lavadora' => [15, 10, 20, 8],
            'llenadora' => [12, 8, 15, 7],
        ];
        // Último CIP completo de cada equipo. En "atención" la llenadora queda próxima a vencer, como en la maqueta.
        $last = [
            'lavadora' => $now->subDays(1 + ($index * 13) % 4)->subHours(($index * 7) % 10)->setSecond(0),
            'llenadora' => $scenario === 'warning'
                ? $now->subDays(6)->subHours(10)->subMinutes($index % 50)->setSecond(0)
                : $now->subDays(2 + $index % 3)->subHours(3)->subMinutes(($index * 11) % 60)->setSecond(0),
        ];

        $cycleRows = [];
        $stageRows = [];
        foreach (['lavadora', 'llenadora'] as $typeIndex => $type) {
            $equipment = Equipment::updateOrCreate(['plant_id' => $plant->id, 'type' => $type], ['name' => ucfirst($type)]);
            DB::table('cip_cycles')->where('equipment_id', $equipment->id)->delete();

            for ($week = 0; $week < 57; $week++) {
                $finished = $last[$type]->subDays(7 * $week);
                $durations = $plan[$type];
                if ($type === 'llenadora') {
                    $durations[2] += $week % 2;
                }
                $n = $week * 2 + $typeIndex;
                $incomplete = $week > 0 && $n > 1 && $n % 7 === 0;
                $missing = $incomplete ? ($n % 14 === 0 ? [2, 3] : [$n % 4]) : [];

                $total = 0;
                $stages = [];
                foreach (['saneado', 'enjuague', 'desinfeccion', 'enjuague_final'] as $pos => $stage) {
                    $done = ! in_array($pos, $missing, true);
                    $total += $done ? $durations[$pos] : 0;
                    $stages[] = ['stage' => $stage, 'position' => $pos + 1, 'duration_min' => $done ? $durations[$pos] : null, 'completed' => $done];
                }

                $cycleRows[] = [
                    'equipment_id' => $equipment->id,
                    'plc_cycle_id' => sprintf('%s-%04d-%03d', strtoupper(substr($type, 0, 3)), $index + 1, 57 - $week),
                    'started_at' => $finished->subMinutes(array_sum($durations))->format('Y-m-d H:i:s'),
                    'finished_at' => $finished->format('Y-m-d H:i:s'),
                    'total_min' => $total,
                    'result' => $incomplete ? 'incompleto' : 'finalizado',
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ];
                $stageRows[] = $stages;
            }
        }

        DB::table('cip_cycles')->insert($cycleRows);

        // Recuperar ids para las etapas (insert masivo no devuelve ids en todos los motores).
        $ids = DB::table('cip_cycles')
            ->whereIn('equipment_id', Equipment::where('plant_id', $plant->id)->pluck('id'))
            ->pluck('id', 'plc_cycle_id');

        $rows = [];
        foreach ($cycleRows as $i => $cycle) {
            foreach ($stageRows[$i] as $stage) {
                $rows[] = ['cip_cycle_id' => $ids[$cycle['plc_cycle_id']]] + $stage;
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('cip_cycle_stages')->insert($chunk);
        }
    }
}
