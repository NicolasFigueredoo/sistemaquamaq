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
 *   ok ──(fuera de setpoint)──▶ alarma ──(vuelve a cumplir)──▶ ok
 *
 * En las variables con rango (mínimo y máximo) sale de parámetro tanto por baja como por alta.
 * No hay estado intermedio: apenas sale de parámetro es alarma (roja) y queda registrada en
 * alarm_events, con inicio = momento en que salió de parámetro y fin = momento en que se normalizó.
 * Con la planta desconectada no se registran alarmas: al cortarse se cierran las abiertas (release).
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

    /** La variable deja de usarse en la planta: cierra su alarma abierta y borra su estado. */
    public function dismiss(int $plantId, Variable $variable, CarbonImmutable $at): void
    {
        $state = PlantVariableState::where('plant_id', $plantId)->where('variable_id', $variable->id)->first();
        if (! $state) {
            return;
        }

        $this->normalize($state, $at);

        PlantVariableState::where('plant_id', $plantId)->where('variable_id', $variable->id)->delete();
    }

    /** La planta perdió la conexión: cierra sus alarmas abiertas. Se conserva el último valor recibido. */
    public function release(int $plantId, CarbonImmutable $at): void
    {
        PlantVariableState::where('plant_id', $plantId)
            ->where('state', '!=', VariableState::Ok->value)
            ->get()
            ->each(function (PlantVariableState $state) use ($at) {
                $this->normalize($state, $at);
                $state->save();
            });
    }

    /** Cierra la alarma abierta, si la hay, y deja la variable en ok. */
    private function normalize(PlantVariableState $state, CarbonImmutable $at): void
    {
        $event = $state->open_alarm_event_id ? AlarmEvent::find($state->open_alarm_event_id) : null;
        if ($event && $event->ended_at === null) {
            $event->ended_at = $at;
            $event->duration_s = max(0, (int) $event->started_at->diffInSeconds($at));
            $event->save();
        }

        $state->state = VariableState::Ok;
        $state->out_since = null;
        $state->worst_value = null;
        $state->open_alarm_event_id = null;
    }

    private function apply(PlantVariableState $state, Variable $variable, PlantSetpoint $setpoint, float $value, CarbonImmutable $at): void
    {
        if ($variable->complies($value, $setpoint->setpoint, $setpoint->setpoint_high)) {
            $this->normalize($state, $at);

            return;
        }

        if ($state->out_since === null) {
            $state->out_since = $at;
            $state->worst_value = $value;
        } else {
            $state->worst_value = $variable->worse($state->worst_value ?? $value, $value, $setpoint->setpoint, $setpoint->setpoint_high);
        }

        if ($state->state !== VariableState::Alarma) {
            $state->state = VariableState::Alarma;
            $event = AlarmEvent::create([
                'plant_id' => $state->plant_id,
                'variable_id' => $variable->id,
                'started_at' => $state->out_since,
                'setpoint_at_start' => $variable->breachedLimit($state->worst_value, $setpoint->setpoint, $setpoint->setpoint_high),
                'worst_value' => $state->worst_value,
            ]);
            $state->open_alarm_event_id = $event->id;
        } elseif ($state->open_alarm_event_id) {
            AlarmEvent::whereKey($state->open_alarm_event_id)->update(['worst_value' => $state->worst_value]);
        }
    }
}
