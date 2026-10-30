<?php

namespace App\Console\Commands;

use App\Models\CipCycle;
use App\Models\Plant;
use App\Services\Telemetry\CipService;
use App\Services\Telemetry\PlantStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Simula a mano una marca de etapa del PLC (lo mismo que llega por Exemys), para probar el CIP sin el equipo.
 * Ej.: php artisan cip:signal quamaq-prueba-exemys lavadora saneado 1
 */
class CipSignal extends Command
{
    protected $signature = 'cip:signal
        {plant : código de la planta}
        {equipment : lavadora | llenadora}
        {stage : saneado | enjuague | desinfeccion | enjuague_final}
        {state : 1 = etapa corriendo, 0 = terminada}
        {--at= : Fecha y hora de la marca (por defecto ahora)}';

    protected $description = 'Simula el cambio de una marca de etapa CIP del PLC.';

    public function handle(CipService $cip, PlantStatusService $status): int
    {
        $plant = Plant::with('gateways')->where('code', $this->argument('plant'))->first();
        $gateway = $plant?->gateways->first();
        if (! $gateway) {
            $this->error('Planta inexistente o sin gateway.');

            return self::FAILURE;
        }

        $equipment = $this->argument('equipment');
        if (! in_array($equipment, ['lavadora', 'llenadora'], true)) {
            $this->error('Equipo inválido. Usá lavadora o llenadora.');

            return self::FAILURE;
        }

        try {
            $at = $this->option('at')
                ? CarbonImmutable::parse($this->option('at'), config('telemetry.gateway_timezone'))
                : CarbonImmutable::now();
            $changed = $cip->signal($gateway, $equipment, $this->argument('stage'), (bool) (int) $this->argument('state'), $at);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $status->refresh($plant, CarbonImmutable::now());

        $last = CipCycle::whereHas('equipment', fn ($q) => $q->where('plant_id', $plant->id)->where('type', $equipment))
            ->latest('id')->with('stages')->first();
        $this->info($changed ? 'Marca registrada.' : 'Sin cambios (mismo estado, muestra vieja o primera muestra de la marca).');
        if ($last) {
            $this->line(sprintf('Último ciclo %s: %s', $last->plc_cycle_id, $last->result));
            $this->table(['Etapa', 'Inicio', 'Fin', 'Min', 'Cumplida'], $last->stages->map(fn ($s) => [
                CipCycle::STAGE_LABELS[$s->stage], $s->started_at?->format('H:i:s') ?? '-', $s->ended_at?->format('H:i:s') ?? '-',
                $s->duration_min ?? '-', $s->completed ? 'sí' : 'no',
            ])->all());
        }

        return self::SUCCESS;
    }
}
