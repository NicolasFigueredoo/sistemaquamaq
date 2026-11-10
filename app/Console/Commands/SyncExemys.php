<?php

namespace App\Console\Commands;

use App\Models\Gateway;
use App\Models\Plant;
use App\Models\PlantSetpoint;
use App\Models\Variable;
use App\Services\Exemys\ExemysClient;
use App\Services\Telemetry\CipService;
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
 * Los canales de cip_map son las marcas de etapa del PLC: cada cambio 0/1 alimenta CipService::signal().
 * Los de production_map dicen si cada equipo está produciendo (ver ProductionService).
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

    public function handle(TelemetryIngestor $ingestor, GatewayPresence $presence, PlantStatusService $status, CipService $cip): int
    {
        $cfg = config('telemetry.exemys');
        $unitId = (int) ($this->option('unit') ?: $cfg['unit_id']);
        $map = $cfg['io_map'];
        $cipMap = $cfg['cip_map'] ?? [];

        if ($unitId <= 0 || (! $map && ! $cipMap)) {
            $this->error('Falta EXEMYS_UNIT_ID o el mapeo de canales (telemetry.exemys.io_map).');

            return self::FAILURE;
        }

        $gateway = $this->gateway($unitId, $cfg);
        $client = ExemysClient::fromConfig();
        $now = CarbonImmutable::now();

        try {
            $current = $client->currentValues($unitId);

            // ── Histórico: desde la última lectura guardada (con 1 h de solapamiento, los duplicados se descartan) ──
            $last = $gateway->plant->last_reading_at;
            $since = match (true) {
                (bool) $this->option('days') => $now->subDays((int) $this->option('days')),
                $last !== null => CarbonImmutable::parse($last)->subHour(),
                default => $now->subDays($cfg['backfill_days']),
            };
            // Lo anterior a esta fecha no es comparable (canales sin convertir): no se importa.
            if ($cfg['data_from']) {
                $since = $since->max(CarbonImmutable::parse($cfg['data_from'], config('telemetry.gateway_timezone')));
            }

            $history = $client->historyValues($unitId, $since, $now);
            $records = [];
            $cipChanges = 0;
            foreach ($history['records'] as $row) {
                $cipChanges += $this->cipSignals($cip, $gateway, $row['values'], $cipMap, $row['date'], $row['flagged']);
                // "*" = registro todavía abierto en Exemys: se toma en la próxima corrida.
                $records[] = $this->record($row['values'], $cfg, $row['date'], $row['flagged']);
            }
            // Con el equipo desconectado el histórico lo entrega la plataforma: no cuenta como señal de vida.
            $saved = $records ? $ingestor->telemetry($gateway, ['records' => $records], $now, $current['online']) : 0;

            // ── Valor actual ──
            if ($current['online']) {
                $byIo = array_column($current['ios'], 'value', 'id');
                $at = $now->subSeconds($this->seconds($current['last_report']));
                $cipChanges += $this->cipSignals($cip, $gateway, $byIo, $cipMap, $at);
                $saved += $ingestor->telemetry($gateway, $this->record($byIo, $cfg, $at), $now);
                if ($cipChanges) {
                    $presence->seen($gateway->refresh(), $now);
                    $status->refresh($gateway->plant, $now);
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
            '%s: %d registros históricos, %d lecturas nuevas, %d cambios de etapa CIP · equipo %s',
            $gateway->plant->name, count($records), $saved, $cipChanges, $current['online'] ? 'en línea' : 'desconectado',
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
            PlantSetpoint::firstOrCreate(['plant_id' => $plant->id, 'variable_id' => $v->id], ['setpoint' => $v->default_setpoint, 'setpoint_high' => $v->default_setpoint_high]);
        }

        $this->info("Planta creada: {$plant->name} (/plantas/{$plant->code})");

        return Gateway::create(['plant_id' => $plant->id, 'serial' => $serial, 'model' => self::GATEWAY_MODEL])->load('plant');
    }

    /** Una muestra en el formato del TelemetryIngestor. */
    private function record(array $byIo, array $cfg, CarbonImmutable $at, array $skip = []): array
    {
        return [
            'ts' => $at->toIso8601String(),
            'values' => $this->mapValues($byIo, $cfg['io_map'], $skip),
            'producing' => array_map(fn (float $v) => $v >= 0.5, $this->mapValues($byIo, $cfg['production_map'] ?? [], $skip)),
        ];
    }

    /** @return array<string, float> key del mapa => valor */
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

    /** @return int cuántas marcas cambiaron algún ciclo */
    private function cipSignals(CipService $cip, Gateway $gateway, array $byIo, array $cipMap, CarbonImmutable $at, array $skip = []): int
    {
        $marks = [];
        foreach ($cipMap as $ioId => $target) {
            if (! isset($byIo[$ioId]) || in_array($ioId, $skip, true)) {
                continue;
            }
            [$equipment, $stage] = explode('.', $target, 2);
            $marks[] = ['equipment' => $equipment, 'stage' => $stage, 'on' => $byIo[$ioId] >= 0.5];
        }

        $modes = [];
        foreach (config('telemetry.exemys.cip_hold_map', []) as $ioId => $equipment) {
            if (isset($byIo[$ioId]) && ! in_array($ioId, $skip, true)) {
                $modes[$equipment] = $byIo[$ioId] >= 0.5;
            }
        }

        // Modo CIP: el que se enciende va antes que las etapas (para que un saneado que arranca en
        // la misma muestra cuente); el que se apaga, después (un enjuague final que termina en la
        // misma muestra cierra el ciclo como finalizado).
        $changes = 0;
        foreach (array_filter($modes) as $equipment => $on) {
            $changes += (int) $cip->hold($gateway, $equipment, true, $at);
        }
        $changes += $cip->signals($gateway, $marks, $at);
        foreach (array_keys(array_filter($modes, fn ($on) => ! $on)) as $equipment) {
            $changes += (int) $cip->hold($gateway, $equipment, false, $at);
        }

        return $changes;
    }

    private function seconds(string $hms): int
    {
        $parts = array_map('intval', explode(':', $hms));

        return count($parts) === 3 ? $parts[0] * 3600 + $parts[1] * 60 + $parts[2] : 0;
    }
}
