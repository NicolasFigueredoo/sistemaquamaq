<?php

namespace App\Services\Telemetry;

use App\Models\Gateway;
use App\Models\MqttInbox;
use App\Models\PlantSetpoint;
use App\Models\Variable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Punto de entrada único de los datos del gateway, venga por MQTT o por HTTP.
 */
class TelemetryIngestor
{
    public function __construct(
        private PayloadParser $parser,
        private VariableEvaluator $evaluator,
        private PlantStatusService $status,
        private GatewayPresence $presence,
        private CipService $cip,
    ) {}

    /**
     * Mensaje MQTT crudo: lo guarda en mqtt_inbox, lo procesa y anota el resultado.
     * Topic: {prefix}/{serial}/{telemetry|cip|status}
     */
    public function handleMqtt(string $topic, string $payload, ?CarbonImmutable $receivedAt = null): MqttInbox
    {
        $receivedAt ??= CarbonImmutable::now();
        $parts = explode('/', trim($topic, '/'));
        $serial = $parts[count($parts) - 2] ?? null;
        $kind = $parts[count($parts) - 1] ?? null;

        $gateway = $serial ? Gateway::where('serial', $serial)->first() : null;

        $inbox = MqttInbox::create([
            'gateway_id' => $gateway?->id,
            'topic' => mb_substr($topic, 0, 255),
            'payload' => $payload,
            'received_at' => $receivedAt,
        ]);

        $this->process($inbox, $gateway, (string) $kind, $receivedAt);

        return $inbox;
    }

    /** Procesa (o reprocesa) un mensaje guardado en la bandeja. */
    public function process(MqttInbox $inbox, ?Gateway $gateway, string $kind, CarbonImmutable $receivedAt): void
    {
        try {
            if (! $gateway) {
                throw new InvalidArgumentException('Gateway no registrado para el topic '.$inbox->topic);
            }

            match ($kind) {
                'telemetry' => $this->telemetry($gateway, $inbox->payload, $receivedAt),
                'cip' => $this->cipCycle($gateway, $inbox->payload, $receivedAt),
                'status' => $this->statusMessage($gateway, $inbox->payload, $receivedAt),
                default => throw new InvalidArgumentException("Tipo de mensaje desconocido: {$kind}"),
            };

            $inbox->forceFill(['processed_at' => CarbonImmutable::now(), 'error' => null])->save();
        } catch (\Throwable $e) {
            $inbox->forceFill(['error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            Log::warning('telemetry: mensaje rechazado', ['inbox_id' => $inbox->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return int cantidad de lecturas nuevas guardadas (los duplicados no cuentan)
     */
    public function telemetry(Gateway $gateway, mixed $payload, ?CarbonImmutable $receivedAt = null): int
    {
        $receivedAt ??= CarbonImmutable::now();
        $records = $this->parser->telemetry($payload, $receivedAt);

        return DB::transaction(function () use ($gateway, $records, $receivedAt) {
            $plant = $gateway->plant()->lockForUpdate()->firstOrFail();
            $variables = Variable::catalog();
            $setpoints = $this->setpointsFor($plant->id, $variables);
            $inserted = 0;
            $latest = $plant->last_reading_at ? CarbonImmutable::parse($plant->last_reading_at) : null;

            foreach ($records as $record) {
                $measuredAt = $record['measured_at'];

                foreach ($record['values'] as $key => $value) {
                    $variable = $variables->get($key);
                    if (! $variable) {
                        continue; // variable desconocida: se ignora sin romper el resto del mensaje
                    }

                    $isNew = DB::table('readings')->insertOrIgnore([
                        'plant_id' => $plant->id,
                        'variable_id' => $variable->id,
                        'gateway_id' => $gateway->id,
                        'value' => $value,
                        'measured_at' => $measuredAt,
                        'received_at' => $receivedAt,
                    ]) > 0;

                    if (! $isNew) {
                        continue;
                    }

                    $inserted++;
                    $this->addToHourly($plant->id, $variable->id, $measuredAt, $value);
                    $this->evaluator->evaluate($plant->id, $variable, $setpoints[$variable->id], $value, $measuredAt);
                }

                if (! $latest || $measuredAt->greaterThan($latest)) {
                    $latest = $measuredAt;
                }
            }

            if ($latest) {
                $plant->last_reading_at = $latest;
                $plant->save();
            }

            $this->presence->seen($gateway, $receivedAt);
            $this->status->refresh($plant, $receivedAt);

            return $inserted;
        });
    }

    public function cipCycle(Gateway $gateway, mixed $payload, ?CarbonImmutable $receivedAt = null): void
    {
        $receivedAt ??= CarbonImmutable::now();
        $data = $this->parser->cip($payload, $receivedAt);

        $this->cip->record($gateway, $data);
        $this->presence->seen($gateway, $receivedAt);
        $this->status->refresh($gateway->plant, $receivedAt);
    }

    /** Mensaje de presencia: "online" / "offline" (ideal como Last Will del gateway). */
    public function statusMessage(Gateway $gateway, mixed $payload, ?CarbonImmutable $receivedAt = null): void
    {
        $receivedAt ??= CarbonImmutable::now();
        $text = is_string($payload) ? trim($payload) : json_encode($payload);
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;
        $state = strtolower((string) (is_array($decoded) ? ($decoded['status'] ?? $decoded['state'] ?? '') : $text));

        if (in_array($state, ['offline', '0', 'false', 'disconnected'], true)) {
            $this->presence->offline($gateway, $gateway->last_seen_at ? CarbonImmutable::parse($gateway->last_seen_at) : $receivedAt);
        } else {
            $this->presence->seen($gateway, $receivedAt);
        }

        $this->status->refresh($gateway->plant, $receivedAt);
    }

    /**
     * @return array<int, PlantSetpoint> indexado por variable_id; crea los que falten con el valor por defecto.
     */
    public function setpointsFor(int $plantId, $variables): array
    {
        $existing = PlantSetpoint::where('plant_id', $plantId)->get()->keyBy('variable_id');
        $out = [];
        foreach ($variables as $variable) {
            $out[$variable->id] = $existing->get($variable->id) ?? PlantSetpoint::create([
                'plant_id' => $plantId,
                'variable_id' => $variable->id,
                'setpoint' => $variable->default_setpoint,
            ])->refresh();
        }

        return $out;
    }

    private function addToHourly(int $plantId, int $variableId, CarbonImmutable $measuredAt, float $value): void
    {
        $hour = $measuredAt->startOfHour();
        $key = ['plant_id' => $plantId, 'variable_id' => $variableId, 'hour' => $hour];

        $row = DB::table('readings_hourly')->where($key)->lockForUpdate()->first();

        if (! $row) {
            DB::table('readings_hourly')->insert($key + [
                'avg_value' => $value, 'min_value' => $value, 'max_value' => $value, 'samples' => 1,
            ]);

            return;
        }

        $samples = (int) $row->samples + 1;
        DB::table('readings_hourly')->where($key)->update([
            'avg_value' => round(((float) $row->avg_value * ($samples - 1) + $value) / $samples, 4),
            'min_value' => min((float) $row->min_value, $value),
            'max_value' => max((float) $row->max_value, $value),
            'samples' => $samples,
        ]);
    }
}
