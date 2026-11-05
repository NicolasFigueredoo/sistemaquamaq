<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La tarjeta "Producto alcalino" (µS/cm) pasa a "Conductividad del agua" (mS/cm), como en la
 * tabla de entradas: se conserva la key `alkaline` y los datos guardados se pasan a la unidad nueva.
 * Además, el rango por defecto del dióxido de cloro pasa a 0,15 - 0,30 ppm.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('variables')->where('key', 'alkaline')->update([
            'name' => 'Conductividad del agua',
            'short_name' => 'Conductividad del agua',
            'alert_name' => 'Conductividad del agua',
            'unit' => 'mS/cm',
            'decimals' => 2,
            'default_setpoint' => 0.3,
            'setpoint_min' => 0,
            'setpoint_max' => 20,
            'chart_min' => 0,
            'chart_max' => 20,
        ]);
        $this->scaleAlkaline('/ 1000.0');

        DB::table('variables')->where('key', 'chlorine_dioxide')->update(['default_setpoint' => 0.15, 'default_setpoint_high' => 0.30]);
        $this->moveDioxideRange([0.20, 0.80], [0.15, 0.30]);
    }

    public function down(): void
    {
        DB::table('variables')->where('key', 'alkaline')->update([
            'name' => 'Producto alcalino',
            'short_name' => 'Producto alcalino',
            'alert_name' => 'Conductividad del producto alcalino',
            'unit' => 'µS/cm',
            'decimals' => 0,
            'default_setpoint' => 300,
            'setpoint_min' => 0,
            'setpoint_max' => 20000,
            'chart_min' => 150,
            'chart_max' => 525,
        ]);
        $this->scaleAlkaline('* 1000.0');

        DB::table('variables')->where('key', 'chlorine_dioxide')->update(['default_setpoint' => 0.20, 'default_setpoint_high' => 0.80]);
        $this->moveDioxideRange([0.15, 0.30], [0.20, 0.80]);
    }

    private function scaleAlkaline(string $operation): void
    {
        $variableId = DB::table('variables')->where('key', 'alkaline')->value('id');
        if (! $variableId) {
            return;
        }

        $columns = [
            'plant_setpoints' => ['setpoint'],
            'readings' => ['value'],
            'readings_hourly' => ['avg_value', 'min_value', 'max_value'],
            'plant_variable_states' => ['last_value', 'worst_value'],
            'alarm_events' => ['setpoint_at_start', 'worst_value'],
        ];
        foreach ($columns as $table => $names) {
            DB::table($table)->where('variable_id', $variableId)->update(
                collect($names)->mapWithKeys(fn ($c) => [$c => DB::raw("{$c} {$operation}")])->all(),
            );
        }

        DB::table('setpoint_changes')->where('field', 'alkaline')->update([
            'old_value' => DB::raw("old_value {$operation}"),
            'new_value' => DB::raw("new_value {$operation}"),
        ]);
    }

    /** Solo las plantas que siguen con el rango por defecto: las configuradas a mano no se tocan. */
    private function moveDioxideRange(array $from, array $to): void
    {
        $variableId = DB::table('variables')->where('key', 'chlorine_dioxide')->value('id');
        if (! $variableId) {
            return;
        }

        DB::table('plant_setpoints')
            ->where('variable_id', $variableId)
            ->where('setpoint', $from[0])
            ->where('setpoint_high', $from[1])
            ->update(['setpoint' => $to[0], 'setpoint_high' => $to[1]]);
    }
};
