<?php

namespace App\Console\Commands;

use App\Models\AlarmEvent;
use App\Models\CipCycle;
use App\Models\Gateway;
use App\Models\PlantVariableState;
use App\Services\Telemetry\PlantStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra lo generado por `telemetry:simulate --exemys` antes de pasar a datos reales.
 * Solo toca la planta de la unidad Exemys. Setpoints, usuarios y configuración quedan como están.
 */
class PurgeExemysSimulated extends Command
{
    protected $signature = 'exemys:purge-simulated {--unit= : unitID de la plataforma (por defecto EXEMYS_UNIT_ID)}';

    protected $description = 'Borra lecturas, alarmas y ciclos CIP de la planta de Exemys para empezar con datos reales.';

    public function handle(PlantStatusService $status): int
    {
        $unitId = (int) ($this->option('unit') ?: config('telemetry.exemys.unit_id'));
        $gateway = Gateway::with('plant')->where('serial', "EXEMYS-{$unitId}")->first();
        if (! $gateway) {
            $this->error("No hay gateway para la unidad Exemys {$unitId}.");

            return self::FAILURE;
        }

        $plant = $gateway->plant;
        if (! $this->confirm("Se borran TODAS las lecturas, alarmas y ciclos CIP de \"{$plant->name}\". ¿Continuar?")) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plant) {
            $equipment = $plant->equipment()->pluck('id');

            DB::table('readings')->where('plant_id', $plant->id)->delete();
            DB::table('readings_hourly')->where('plant_id', $plant->id)->delete();
            AlarmEvent::where('plant_id', $plant->id)->delete();
            PlantVariableState::where('plant_id', $plant->id)->delete();
            CipCycle::whereIn('equipment_id', $equipment)->delete();
            DB::table('cip_stage_states')->whereIn('equipment_id', $equipment)->delete();
            $plant->equipment()->update(['producing' => null, 'producing_at' => null]);

            $plant->last_reading_at = null;
            $plant->save();
        });

        $status->refresh($plant->fresh(), CarbonImmutable::now());
        $this->info("Planta {$plant->name} limpia. El próximo exemys:sync trae los datos reales.");

        return self::SUCCESS;
    }
}
