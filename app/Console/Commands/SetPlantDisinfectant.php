<?php

namespace App\Console\Commands;

use App\Models\Plant;
use App\Models\SetpointChange;
use App\Models\Variable;
use App\Services\Telemetry\PlantStatusService;
use App\Services\Telemetry\VariableEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * El desinfectante es propio de la instalación de cada planta (ozono o dióxido de cloro) y no se elige
 * desde la pantalla de configuración: se define acá, al dar de alta la planta o si cambia el equipo.
 */
class SetPlantDisinfectant extends Command
{
    protected $signature = 'plant:disinfectant {plant : código de la planta (slug)} {disinfectant? : ozone | chlorine_dioxide (sin valor, muestra el actual)}';

    protected $description = 'Muestra o define el desinfectante (ozono o dióxido de cloro) de una planta.';

    public function handle(VariableEvaluator $evaluator, PlantStatusService $status): int
    {
        $plant = Plant::where('code', $this->argument('plant'))->first();
        if (! $plant) {
            $this->error('No existe una planta con código '.$this->argument('plant'));

            return self::FAILURE;
        }

        $settings = $plant->settingsOrDefault();
        $new = $this->argument('disinfectant');
        if ($new === null) {
            $this->info("{$plant->name}: {$settings->disinfectant}");

            return self::SUCCESS;
        }
        if (! in_array($new, Variable::DISINFECTANTS, true)) {
            $this->error('Desinfectante inválido. Opciones: '.implode(', ', Variable::DISINFECTANTS));

            return self::FAILURE;
        }
        if ($settings->disinfectant === $new) {
            $this->info("{$plant->name} ya usa {$new}.");

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        DB::transaction(function () use ($plant, $settings, $new, $evaluator, $status, $now) {
            // El desinfectante anterior deja de contar para el estado de la planta.
            $evaluator->dismiss($plant->id, Variable::catalog()[$settings->disinfectant], $now);
            $settings->disinfectant = $new;
            $settings->save();

            SetpointChange::create([
                'plant_id' => $plant->id, 'field' => "disinfectant:{$new}", 'old_value' => null,
                'new_value' => null, 'user_id' => null, 'changed_at' => $now,
            ]);

            $status->refresh($plant->fresh(), $now);
        });

        $this->info("{$plant->name} ahora usa {$new}.");

        return self::SUCCESS;
    }
}
