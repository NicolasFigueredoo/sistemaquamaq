<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\Gateway;
use App\Models\PlantSetpoint;
use App\Models\Variable;
use App\Services\Telemetry\CipService;
use App\Services\Telemetry\TelemetryIngestor;
use App\Support\DemoSignals;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Simula los EM300 para probar sin hardware. Por defecto escribe directo en la base
 * (mismo código que usa el ingestor); con --mqtt publica al broker como lo haría un gateway real.
 */
class SimulateTelemetry extends Command
{
    protected $signature = 'telemetry:simulate
        {--loop : Sigue enviando cada --interval segundos hasta Ctrl+C}
        {--interval=10 : Segundos entre envíos}
        {--plant=* : Solo estas plantas (código)}
        {--except=* : Plantas a dejar "sin conexión" (por defecto las dos de la demo)}
        {--mqtt : Publicar al broker en lugar de escribir directo}';

    protected $description = 'Genera telemetría de prueba para las plantas (demo sin gateways reales).';

    public function handle(TelemetryIngestor $ingestor, CipService $cip): int
    {
        $except = $this->option('except') ?: ['2-vascos-srl', 'ruben-omar-meder'];
        $gateways = Gateway::query()
            ->with('plant.settings')
            ->where('model', '!=', SyncExemys::GATEWAY_MODEL) // la planta de Exemys tiene datos reales
            ->whereHas('plant', function ($q) use ($except) {
                $q->where('active', true)->whereNotIn('code', $except);
                if ($codes = $this->option('plant')) {
                    $q->whereIn('code', $codes);
                }
            })
            ->get()
            ->unique('plant_id');

        if ($gateways->isEmpty()) {
            $this->error('No hay gateways para simular. ¿Corriste php artisan db:seed?');

            return self::FAILURE;
        }

        $mqtt = $this->option('mqtt') ? $this->connect() : null;
        $variables = Variable::catalog();
        $interval = max(1, (int) $this->option('interval'));

        do {
            $now = CarbonImmutable::now()->utc()->startOfSecond();
            foreach ($gateways as $gateway) {
                $payload = json_encode($this->payload($gateway, $variables, $now));
                $prefix = config('telemetry.mqtt.topic_prefix');

                if ($mqtt) {
                    $mqtt->publish("{$prefix}/{$gateway->serial}/telemetry", $payload, 1);
                } else {
                    $ingestor->telemetry($gateway->fresh(), $payload, $now);
                }

                foreach ($this->dueCip($gateway, $cip, $now) as $cycle) {
                    $mqtt
                        ? $mqtt->publish("{$prefix}/{$gateway->serial}/cip", json_encode($cycle), 1)
                        : $ingestor->cipCycle($gateway->fresh(), json_encode($cycle), $now);
                }
            }
            $mqtt?->loop(true, true, 10);

            $this->line(sprintf('[%s] %d plantas enviadas%s', $now->setTimezone(config('telemetry.display_timezone'))->format('H:i:s'), $gateways->count(), $mqtt ? ' por MQTT' : ''));

            if ($this->option('loop')) {
                sleep($interval);
            }
        } while ($this->option('loop'));

        $mqtt?->disconnect();

        return self::SUCCESS;
    }

    private function payload(Gateway $gateway, Collection $variables, CarbonImmutable $now): array
    {
        $phase = $gateway->plant_id * 37;
        $t = $now->getTimestamp() / 3600;
        $setpoints = PlantSetpoint::where('plant_id', $gateway->plant_id)->pluck('setpoint', 'variable_id');

        // Cada planta tiene un desvío de 15 minutos cada 4 horas en una de las variables.
        $minute = intdiv($now->getTimestamp(), 60);
        $inDip = (($minute + $gateway->plant_id * 53) % 240) < 15;
        $dipKey = ['ozone', 'temperature', 'alkaline'][$gateway->plant_id % 3];
        $dipBy = ['ozone' => 0.07, 'temperature' => 4, 'alkaline' => 3];

        $values = [];
        foreach ($variables as $v) {
            $value = DemoSignals::value($v->key, $t, $phase, false);
            $setpoint = (float) ($setpoints[$v->id] ?? $v->default_setpoint);

            if (in_array($v->key, ['ozone', 'temperature', 'alkaline'], true)) {
                $value = max($value, $setpoint + $dipBy[$v->key] * 0.4);
                $value += (mt_rand(-100, 100) / 100) * $dipBy[$v->key] * 0.05;
                if ($inDip && $v->key === $dipKey) {
                    $value = $setpoint - $dipBy[$v->key] * (0.5 + mt_rand(0, 50) / 100);
                }
            }

            $values[$v->key] = round($value, $v->decimals + 1);
        }

        return ['ts' => $now->getTimestampMs(), 'values' => $values];
    }

    /** Si un equipo se quedó sin CIP vigente, simula que el PLC informa uno nuevo completo. */
    private function dueCip(Gateway $gateway, CipService $cip, CarbonImmutable $now): array
    {
        $settings = $gateway->plant->settingsOrDefault();
        $cycles = [];

        foreach (Equipment::where('plant_id', $gateway->plant_id)->with('lastCompleteCycle')->get() as $equipment) {
            $status = $cip->statusFor($equipment, $settings, $now);
            if ($status['status']->value !== 'vencido') {
                continue;
            }
            $durations = $equipment->type === 'lavadora' ? [15, 10, 20, 8] : [12, 8, 15, 7];
            $cycles[] = [
                'cycle_id' => strtoupper(substr($equipment->type, 0, 3)).'-SIM-'.$now->format('YmdHis'),
                'equipment' => $equipment->type,
                'started_at' => $now->subMinutes(array_sum($durations))->getTimestampMs(),
                'finished_at' => $now->getTimestampMs(),
                'stages' => array_combine(['saneado', 'enjuague', 'desinfeccion', 'enjuague_final'], $durations),
            ];
        }

        return $cycles;
    }

    private function connect(): MqttClient
    {
        $cfg = config('telemetry.mqtt');
        $client = new MqttClient($cfg['host'], $cfg['port'], 'ivess-simulador-'.getmypid(), MqttClient::MQTT_3_1_1);
        $client->connect((new ConnectionSettings)->setUsername($cfg['username'])->setPassword($cfg['password'])->setUseTls($cfg['tls']), true);
        $this->info("Conectado al broker {$cfg['host']}:{$cfg['port']}");

        return $client;
    }
}
