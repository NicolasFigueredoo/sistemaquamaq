<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cloro en enjuague: hay presencia desde un mínimo en ppm configurable por planta (0,10 por defecto).
 * El setpoint anterior (1) no era un valor del sensor, sino "presencia obligatoria".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('variables')->where('key', 'chlorine')->update([
            'unit' => 'ppm',
            'decimals' => 2,
            'rule' => 'min',
            'default_setpoint' => 0.1,
            'setpoint_min' => 0,
            'setpoint_max' => 2,
        ]);
        $this->moveSetpoint(1, 0.1);
    }

    public function down(): void
    {
        DB::table('variables')->where('key', 'chlorine')->update([
            'unit' => '',
            'decimals' => 0,
            'rule' => 'presence',
            'default_setpoint' => 1,
            'setpoint_min' => null,
            'setpoint_max' => null,
        ]);
        DB::table('plant_setpoints')
            ->where('variable_id', DB::table('variables')->where('key', 'chlorine')->value('id'))
            ->update(['setpoint' => 1]);
    }

    private function moveSetpoint(float $from, float $to): void
    {
        $variableId = DB::table('variables')->where('key', 'chlorine')->value('id');
        if ($variableId) {
            DB::table('plant_setpoints')->where('variable_id', $variableId)->where('setpoint', $from)->update(['setpoint' => $to]);
        }
    }
};
