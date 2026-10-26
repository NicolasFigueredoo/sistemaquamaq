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

        // Solo las variables con rango definido se configuran desde el modal.
        $editable = Variable::catalog()->filter(fn ($v) => $v->setpoint_min !== null && $v->setpoint_max !== null);

        $rules = [
            'cip_period_days' => ['required', 'integer', 'min:1', 'max:30'],
        ];
        foreach ($editable as $v) {
            $rules["setpoints.{$v->key}"] = ['required', 'numeric', "min:{$v->setpoint_min}", "max:{$v->setpoint_max}"];
        }
        $data = $request->validate($rules, [], [
            'cip_period_days' => 'periodicidad CIP',
        ] + $editable->mapWithKeys(fn ($v) => ["setpoints.{$v->key}" => mb_strtolower($v->name)])->all());

        $now = CarbonImmutable::now();
        $userId = $request->user()->id;

        DB::transaction(function () use ($plant, $data, $editable, $ingestor, $evaluator, $status, $now, $userId) {
            $changes = [];
            $settings = $plant->settingsOrDefault();
            foreach (['cip_period_days'] as $field) {
                if ((float) $settings->{$field} !== (float) $data[$field]) {
                    $changes[] = [$field, $settings->{$field}, $data[$field]];
                    $settings->{$field} = $data[$field];
                }
            }
            $settings->updated_by = $userId;
            $settings->save();

            $setpoints = $ingestor->setpointsFor($plant->id, Variable::catalog());
            foreach ($editable as $v) {
                $sp = $setpoints[$v->id];
                $new = round((float) $data['setpoints'][$v->key], 4);
                if (abs($sp->setpoint - $new) < 0.00001) {
                    continue;
                }
                $changes[] = [$v->key, $sp->setpoint, $new];
                $sp->setpoint = $new;
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
