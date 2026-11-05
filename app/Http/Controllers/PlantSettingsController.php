<?php

namespace App\Http\Controllers;

use App\Models\Plant;
use App\Models\PlantVariableState;
use App\Models\SetpointChange;
use App\Models\Variable;
use App\Services\Telemetry\PlantStatusService;
use App\Services\Telemetry\TelemetryIngestor;
use App\Services\Telemetry\VariableEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlantSettingsController extends Controller
{
    public function update(
        Request $request,
        Plant $plant,
        TelemetryIngestor $ingestor,
        VariableEvaluator $evaluator,
        PlantStatusService $status,
    ): RedirectResponse {
        abort_unless($request->user()->isAdmin(), 403);

        $disinfectant = $request->validate(['disinfectant' => ['required', Rule::in(Variable::DISINFECTANTS)]], [], ['disinfectant' => 'desinfectante'])['disinfectant'];

        // Solo las variables con rango definido se configuran desde el modal, y del desinfectante solo el de la planta.
        $editable = Variable::catalog()
            ->filter(fn ($v) => $v->setpoint_min !== null && $v->setpoint_max !== null)
            ->reject(fn ($v) => in_array($v->key, Variable::DISINFECTANTS, true) && $v->key !== $disinfectant);

        $rules = [
            'cip_period_days' => ['required', 'integer', 'min:1', 'max:30'],
        ];
        $ranged = $editable->where('rule', 'range');
        foreach ($editable as $v) {
            $rules["setpoints.{$v->key}"] = ['required', 'numeric', "min:{$v->setpoint_min}", "max:{$v->setpoint_max}"];
        }
        // Variables con rango: el setpoint es el mínimo y setpoints_high el máximo.
        foreach ($ranged as $v) {
            $rules["setpoints_high.{$v->key}"] = ['required', 'numeric', "max:{$v->setpoint_max}", "gt:setpoints.{$v->key}"];
        }
        $data = $request->validate($rules, [], [
            'cip_period_days' => 'periodicidad CIP',
        ] + $editable->mapWithKeys(fn ($v) => ["setpoints.{$v->key}" => mb_strtolower($v->name).($v->rule === 'range' ? ' (mínimo)' : '')])->all()
          + $ranged->mapWithKeys(fn ($v) => ["setpoints_high.{$v->key}" => mb_strtolower($v->name).' (máximo)'])->all());

        $now = CarbonImmutable::now();
        $userId = $request->user()->id;

        DB::transaction(function () use ($plant, $data, $disinfectant, $editable, $ingestor, $evaluator, $status, $now, $userId) {
            $changes = [];
            $settings = $plant->settingsOrDefault();
            foreach (['cip_period_days'] as $field) {
                if ((float) $settings->{$field} !== (float) $data[$field]) {
                    $changes[] = [$field, $settings->{$field}, $data[$field]];
                    $settings->{$field} = $data[$field];
                }
            }
            if ($settings->disinfectant !== $disinfectant) {
                // El desinfectante anterior deja de contar para el estado de la planta.
                $evaluator->dismiss($plant->id, Variable::catalog()[$settings->disinfectant], $now);
                $changes[] = ["disinfectant:{$disinfectant}", null, null];
                $settings->disinfectant = $disinfectant;
            }
            $settings->updated_by = $userId;
            $settings->save();

            $setpoints = $ingestor->setpointsFor($plant->id, Variable::catalog());
            foreach ($editable as $v) {
                $sp = $setpoints[$v->id];
                $new = round((float) $data['setpoints'][$v->key], 4);
                $newHigh = isset($data['setpoints_high'][$v->key]) ? round((float) $data['setpoints_high'][$v->key], 4) : $sp->setpoint_high;
                $lowChanged = abs($sp->setpoint - $new) >= 0.00001;
                $highChanged = abs(($sp->setpoint_high ?? 0) - ($newHigh ?? 0)) >= 0.00001;
                if (! $lowChanged && ! $highChanged) {
                    continue;
                }
                if ($lowChanged) {
                    $changes[] = [$v->key, $sp->setpoint, $new];
                }
                if ($highChanged) {
                    $changes[] = ["{$v->key}_high", $sp->setpoint_high, $newHigh];
                }
                $sp->setpoint = $new;
                $sp->setpoint_high = $newHigh;
                $sp->updated_by = $userId;
                $sp->save();

                // El valor actual se juzga contra el setpoint nuevo.
                $state = PlantVariableState::where('plant_id', $plant->id)->where('variable_id', $v->id)->first();
                if ($state) {
                    $evaluator->reevaluate($state, $v, $sp, $now);
                }
            }

            foreach ($changes as [$field, $old, $new]) {
                SetpointChange::create([
                    'plant_id' => $plant->id, 'field' => $field, 'old_value' => $old,
                    'new_value' => $new, 'user_id' => $userId, 'changed_at' => $now,
                ]);
            }

            $status->refresh($plant->fresh(), $now);
        });

        return back();
    }
}
