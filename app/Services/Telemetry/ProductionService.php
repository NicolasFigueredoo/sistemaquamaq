<?php

namespace App\Services\Telemetry;

use App\Models\Equipment;
use App\Models\Plant;
use App\Models\Variable;
use Carbon\CarbonImmutable;

/**
 * Señal "en producción" de cada equipo. Un equipo encendido sin producir no interesa: sus variables
 * no se registran ni disparan alarmas, y al detenerse se cierran las que tenía abiertas.
 * Los equipos que nunca informaron la señal (producing = null) se registran siempre.
 */
class ProductionService
{
    public function __construct(private VariableEvaluator $evaluator) {}

    /**
     * Procesa una muestra y devuelve qué equipos estaban detenidos en ese momento. Si la muestra
     * no trae la señal de un equipo vale la última conocida. Las muestras viejas (el sync se
     * solapa con la corrida anterior) no cambian el estado guardado.
     *
     * @param  array<string, bool>  $marks  tipo de equipo => en producción
     * @return list<string> tipos de equipo detenidos
     */
    public function stopped(Plant $plant, array $marks, CarbonImmutable $at): array
    {
        foreach ($marks as $type => $on) {
            $equipment = Equipment::firstOrCreate(['plant_id' => $plant->id, 'type' => $type], ['name' => ucfirst($type)]);
            if ($equipment->producing_at && $at->lessThanOrEqualTo($equipment->producing_at)) {
                continue;
            }

            if (! $on && $equipment->producing !== false) {
                $this->closeVariables($plant->id, $type, $at);
            }
            $equipment->update(['producing' => $on, 'producing_at' => $at]);
        }

        $stored = Equipment::where('plant_id', $plant->id)->where('producing', false)->pluck('type')->all();
        $stopped = array_keys(array_filter($marks, fn (bool $on) => ! $on));

        return array_values(array_unique([...$stopped, ...array_diff($stored, array_keys($marks))]));
    }

    private function closeVariables(int $plantId, string $type, CarbonImmutable $at): void
    {
        foreach (Variable::catalog() as $variable) {
            if ($variable->equipmentType() === $type) {
                $this->evaluator->dismiss($plantId, $variable, $at);
            }
        }
    }
}
