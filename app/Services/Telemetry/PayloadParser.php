<?php

namespace App\Services\Telemetry;

use App\Models\CipCycle;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Convierte lo que manda el gateway (Node-RED del EM300) en estructuras normalizadas.
 *
 * Telemetría — se aceptan tres formas:
 *   {"ts": 1759600000000, "values": {"ozone": 0.55, "temperature": 62.4}}
 *   {"ts": "2026-10-04T14:32:10-03:00", "ozone": 0.55, "temperature": 62.4}
 *   {"records": [ {...}, {...} ]}   ó   [ {...}, {...} ]   (lote, p. ej. al vaciar la cola tras un corte)
 *
 * CIP:
 *   {"cycle_id": "LAV-0412", "equipment": "lavadora", "started_at": ..., "finished_at": ...,
 *    "stages": {"saneado": 15, "enjuague": 10, "desinfeccion": null, "enjuague_final": 8}}
 *   (null o ausente = etapa no cumplida). También se acepta
 *   "stages": [{"stage": "saneado", "minutes": 15, "completed": true}, ...]
 */
class PayloadParser
{
    /**
     * @return list<array{measured_at: CarbonImmutable, values: array<string, float>}>
     */
    public function telemetry(mixed $payload, CarbonImmutable $receivedAt): array
    {
        $payload = $this->decode($payload);

        $records = match (true) {
            isset($payload['records']) && is_array($payload['records']) => $payload['records'],
            array_is_list($payload) => $payload,
            default => [$payload],
        };

        $out = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $ts = $record['ts'] ?? $record['timestamp'] ?? $record['time'] ?? null;
            $values = isset($record['values']) && is_array($record['values'])
                ? $record['values']
                : array_diff_key($record, array_flip(['ts', 'timestamp', 'time', 'serial', 'gateway']));

            $clean = [];
            foreach ($values as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? 1 : 0;
                }
                if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
                    $value = str_replace(',', '.', $value);
                }
                if (is_numeric($value)) {
                    $clean[(string) $key] = (float) $value;
                }
            }

            if ($clean !== []) {
                $out[] = ['measured_at' => $this->timestamp($ts, $receivedAt), 'values' => $clean];
            }
        }

        usort($out, fn ($a, $b) => $a['measured_at'] <=> $b['measured_at']);

        return $out;
    }

    /**
     * @return array{cycle_id: string, equipment: string, started_at: ?CarbonImmutable, finished_at: CarbonImmutable,
     *               stages: list<array{stage: string, position: int, duration_min: ?int, completed: bool}>}
     */
    public function cip(mixed $payload, CarbonImmutable $receivedAt): array
    {
        $payload = $this->decode($payload);

        $equipment = strtolower(trim((string) ($payload['equipment'] ?? $payload['equipo'] ?? '')));
        if (! in_array($equipment, ['lavadora', 'llenadora'], true)) {
            throw new InvalidArgumentException("Equipo CIP desconocido: '{$equipment}'");
        }

        $finishedAt = $this->timestamp($payload['finished_at'] ?? $payload['ts'] ?? null, $receivedAt);
        $startedAt = isset($payload['started_at']) ? $this->timestamp($payload['started_at'], $receivedAt) : null;

        $raw = $payload['stages'] ?? [];
        $byStage = [];
        if (is_array($raw) && array_is_list($raw)) {
            foreach ($raw as $item) {
                $name = $this->stageKey($item['stage'] ?? '');
                if ($name) {
                    $minutes = $item['minutes'] ?? $item['duration_min'] ?? null;
                    $completed = (bool) ($item['completed'] ?? $minutes !== null);
                    $byStage[$name] = ['duration_min' => $minutes === null ? null : (int) round($minutes), 'completed' => $completed];
                }
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $name => $minutes) {
                $name = $this->stageKey((string) $name);
                if ($name) {
                    $byStage[$name] = [
                        'duration_min' => $minutes === null || $minutes === false ? null : (int) round((float) $minutes),
                        'completed' => $minutes !== null && $minutes !== false,
                    ];
                }
            }
        }

        $stages = [];
        foreach (CipCycle::STAGES as $i => $name) {
            $stage = $byStage[$name] ?? ['duration_min' => null, 'completed' => false];
            $stages[] = ['stage' => $name, 'position' => $i + 1] + $stage;
        }

        $cycleId = (string) ($payload['cycle_id'] ?? $payload['id'] ?? $equipment.'-'.$finishedAt->format('YmdHis'));

        return [
            'cycle_id' => $cycleId,
            'equipment' => $equipment,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'stages' => $stages,
        ];
    }

    public function timestamp(mixed $ts, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($ts === null || $ts === '') {
            return $fallback;
        }

        if (is_numeric($ts)) {
            $num = (float) $ts;
            // > 1e11 => milisegundos; si no, segundos.
            $parsed = $num > 1e11
                ? CarbonImmutable::createFromTimestampMs((int) $num, 'UTC')
                : CarbonImmutable::createFromTimestamp((int) $num, 'UTC');
        } else {
            try {
                $parsed = CarbonImmutable::parse((string) $ts, config('telemetry.gateway_timezone'));
            } catch (\Throwable) {
                return $fallback;
            }
        }

        $parsed = $parsed->utc()->startOfSecond();

        // Reloj del gateway adelantado: usamos la hora de llegada.
        if ($parsed->greaterThan($fallback->addSeconds(config('telemetry.max_future_skew_seconds', 300)))) {
            return $fallback;
        }

        return $parsed;
    }

    private function decode(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (! is_array($payload)) {
            throw new InvalidArgumentException('El mensaje no es JSON válido.');
        }

        return $payload;
    }

    private function stageKey(string $name): ?string
    {
        $name = strtolower(trim(str_replace([' ', '-', 'ó'], ['_', '_', 'o'], $name)));
        $name = str_replace('desinfección', 'desinfeccion', $name);

        return in_array($name, CipCycle::STAGES, true) ? $name : null;
    }
}
