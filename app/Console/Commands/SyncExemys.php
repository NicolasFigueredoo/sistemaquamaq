<?php

namespace App\Console\Commands;

use App\Models\Gateway;
use App\Models\Plant;
use App\Models\PlantSetpoint;
use App\Models\Variable;
use App\Services\Exemys\ExemysClient;
use App\Services\Telemetry\GatewayPresence;
use App\Services\Telemetry\PlantStatusService;
use App\Services\Telemetry\TelemetryIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Trae los datos de la unidad Exemys y los guarda como lecturas de su planta, por el mismo
 * TelemetryIngestor que usan los gateways MQTT (alarmas, promedios horarios y estado incluidos).
 *
 * Cada corrida: histórico desde la última lectura (la primera vez, EXEMYS_BACKFILL_DAYS días)
 * + valor actual. Pensado para correr cada minuto desde el scheduler.
 */
class SyncExemys extends Command
{
    public const GATEWAY_MODEL = 'Exemys';

    protected $signature = 'exemys:sync
        {--unit= : unitID de la plataforma (por defecto EXEMYS_UNIT_ID)}
        {--days= : Forzar histórico de los últimos N días}';

    protected $description = 'Sincroniza la unidad Exemys M2M con su planta del panel.';

    public function handle(TelemetryIngestor $ingestor, GatewayPresence $presence, PlantStatusService $status): int
    {
        $cfg = config('telemetry.exemys');
        $unitId = (int) ($this->option('unit') ?: $cfg['unit_id']);
        $map = $cfg['io_map'];

        if ($unitId <= 0 || ! $map) {
            $this->error('Falta EXEMYS_UNIT_ID o el mapeo de canales (telemetry.exemys.io_map).');

            return self::FAILURE;
        }

        $gateway = $this->gateway($unitId, $cfg);
        $client = ExemysClient::fromConfig();
        $now = CarbonImmutable::now();

        try {
            // ── Histórico: desde la última lectura guardada (con 1 h de solapamiento, los duplicados se descartan) ──
            $last = $gateway->plant->last_reading_at;
            $since = match (true) {
                (bool) $this->option('days') => $now->subDays((int) $this->option('days')),
                $last !== null => CarbonImmutable::parse($last)->subHour(),
                default => $now->subDays($cfg['backfill_days']),
            };

            $history = $client->historyValues($unitId, $since, $now);
            $records = [];
            foreach ($history['records'] as $row) {
                // "*" = registro todavía abierto en Exemys: se toma en la próxima corrida.
                $values = $this->mapValues($row['values'], $map, $row['flagged']);
                if ($values) {
                    $records[] = ['ts' => $row['date']->toIso8601String(), 'values' => $values];
                }
            }
            $saved = $records ? $ingestor->telemetry($gateway, ['records' => $records], $now) : 0;

            // ── Valor actual ──
            $current = $client->currentValues($unitId);
            if ($current['online']) {
                $values = $this->mapValues(array_column($current['ios'], 'value', 'id'), $map);
                if ($values) {
                    $saved += $ingestor->telemetry($gateway, ['ts' => $now->subSeconds($this->seconds($current['last_report']))->toIso8601String(), 'values' => $values], $now);
                }
            } else {
                $presence->offline($gateway->refresh(), $now->subSeconds($this->seconds($current['last_report'])));
                $status->refresh($gateway->plant, $now);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s: %d registros históricos, %d lecturas nuevas · equipo %s',
            $gateway->plant->name, count($records), $saved, $current['online'] ? 'en línea' : 'desconectado',
        ));

        return self::SUCCESS;
    }

    /** Planta y gateway de la unidad; se crean la primera vez. */
    private function gateway(int $unitId, array $cfg): Gateway
    {
        $serial = "EXEMYS-{$unitId}";
        $gateway = Gateway::with('plant')->where('serial', $serial)->first();
        if ($gateway) {
            return $gateway;
        }

        $plant = Plant::firstOrCreate(
            ['code' => Str::slug($cfg['plant_name'])],
            ['name' => $cfg['plant_name'], 'location' => $cfg['plant_location'], 'active' => true],
        );
        $plant->settingsOrDefault();
        foreach (Variable::all() as $v) {
            PlantSetpoint::firstOrCreate(['plant_id' => $plant->id, 'variable_id' => $v->id], ['setpoint' => $v->default_setpoint]);
        }

        $this->info("Planta creada: {$plant->name} (/plantas/{$plant->code})");

        return Gateway::create(['plant_id' => $plant->id, 'serial' => $serial, 'model' => self::GATEWAY_MODEL])->load('plant');
    }

    /** @return array<string, float> key de variable => valor */
    private function mapValues(array $byIo, array $map, array $skip = []): array
    {
        $out = [];
        foreach ($map as $ioId => $key) {
            if (isset($byIo[$ioId]) && ! in_array($ioId, $skip, true)) {
                $out[$key] = $byIo[$ioId];
            }
        }

        return $out;
    }

    private function seconds(string $hms): int
    {
        $parts = array_map('intval', explode(':', $hms));

        return count($parts) === 3 ? $parts[0] * 3600 + $parts[1] * 60 + $parts[2] : 0;
    }
}
