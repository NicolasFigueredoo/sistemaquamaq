<?php

namespace App\Services\Telemetry;

use App\Enums\VariableState;
use App\Models\AlarmEvent;
use App\Models\PlantSetpoint;
use App\Models\PlantVariableState;
use App\Models\Variable;
use Carbon\CarbonImmutable;

/**
 * Máquina de estados por planta + variable:
 *
 *   ok ──(fuera de setpoint)──▶ atención ──(sigue fuera ≥ alarm_delay_s)──▶ alarma
 *    ▲                              │                                          │
 *    └─────────(vuelve a cumplir)───┴───────────(vuelve a cumplir)─────────────┘
 *
 * Las variables de Variable::IMMEDIATE_ALARM (temperatura) no tienen atención: pasan directo a alarma.
 * Solo las alarmas quedan registradas en alarm_events, con inicio = momento en que
 * salió de parámetro y fin = momento en que se normalizó.
 */
class VariableEvaluator
{
    public function evaluate(
        int $plantId,
        Variable $variable,
        PlantSetpoint $setpoint,
        float $value,
        CarbonImmutable $measuredAt,
    ): PlantVariableState {
        $state = PlantVariableState::query()
            ->where('plant_id', $plantId)
            ->where('variable_id', $variable->id)
            ->first()
            ?? new PlantVariableState(['plant_id' => $plantId, 'variable_id' => $variable->id, 'state' => VariableState::Ok]);

        // Lecturas más viejas que la última evaluada (reenvíos fuera de orden) se guardan pero no cambian el estado.
        if ($state->last_measured_at && $measuredAt->lessThan($state->last_measured_at)) {
            return $state;
        }

        $state->last_value = $value;
        $state->last_measured_at = $measuredAt;

        $this->apply($state, $variable, $setpoint, $value, $measuredAt);

        $state->save();

        return $state;
    }

    /**
     * Re-evalúa el último valor conocido con un setpoint nuevo (cuando el admin lo cambia).
     */
    public function reevaluate(PlantVariableState $state, Variable $variable, PlantSetpoint $setpoint, CarbonImmutable $now): void
    {
        if ($state->last_value === null) {
            return;
        }

        $this->apply($state, $variable, $setpoint, $state->last_value, $now);
        $state->save();
    }

    private function apply(PlantVariableState $state, Variable $variable, PlantSetpoint $setpoint, float $value, CarbonImmutable $at): void
    {
        if ($variable->complies($value, $setpoint->setpoint)) {
            if ($state->open_alarm_event_id) {
                $event = AlarmEvent::find($state->open_alarm_event_id);
                if ($event && $event->ended_at === null) {
                    $event->ended_at = $at;
                    $event->duration_s = max(0, (int) $event->started_at->diffInSeconds($at));
                    $event->save();
                }
            }
            $state->state = VariableState::Ok;
            $state->out_since = null;
            $state->worst_value = null;
            $state->open_alarm_event_id = null;

            return;
        }

        if ($state->out_since === null) {
            $state->out_since = $at;
            $state->worst_value = $value;
            $state->state = VariableState::Atencion;
        } else {
            $state->worst_value = $variable->worse($state->worst_value ?? $value, $value);
        }

        $outFor = CarbonImmutable::parse($state->out_since)->diffInSeconds($at);
        $delay = $variable->alarmDelay($setpoint->alarm_delay_s);

        if ($state->state !== VariableState::Alarma && ($delay === 0 || $outFor >= $delay)) {
            $state->state = VariableState::Alarma;
            $event = AlarmEvent::create([
                'plant_id' => $state->plant_id,
                'variable_id' => $variable->id,
                'started_at' => $state->out_since,
                'setpoint_at_start' => $setpoint->setpoint,
                'worst_value' => $state->worst_value,
            ]);
            $state->open_alarm_event_id = $event->id;
        } elseif ($state->open_alarm_event_id) {
            AlarmEvent::whereKey($state->open_alarm_event_id)->update(['worst_value' => $state->worst_value]);
        }
    }
}
