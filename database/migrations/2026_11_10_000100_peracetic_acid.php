<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ácido peracético (canal AN4 de Exemys): cuantitativo en ppm, alarma solo por baja.
 * Se da de alta en el catálogo y con su setpoint por defecto en cada planta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('variables')->updateOrInsert(['key' => 'peracetic'], [
            'name' => 'Ácido peracético',
            'short_name' => 'Ácido peracético',
            'alert_name' => 'Ácido peracético',
            'unit' => 'ppm',
            'decimals' => 2,
            'kind' => 'analog',
            'rule' => 'min',
            'default_setpoint' => 0.1,
            'setpoint_min' => 0,
            'setpoint_max' => 100,
            'chart_min' => 0,
            'chart_max' => 1,
            'chart_color' => '#c2702a',
            'sort' => 6,
            'chart_sort' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('variables')->where('key', 'chlorine_dioxide')->update(['sort' => 7]);

        $variableId = DB::table('variables')->where('key', 'peracetic')->value('id');
        foreach (DB::table('plants')->pluck('id') as $plantId) {
            $exists = DB::table('plant_setpoints')->where('plant_id', $plantId)->where('variable_id', $variableId)->exists();
            if (! $exists) {
                DB::table('plant_setpoints')->insert(['plant_id' => $plantId, 'variable_id' => $variableId, 'setpoint' => 0.1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $variableId = DB::table('variables')->where('key', 'peracetic')->value('id');
        if ($variableId) {
            DB::table('plant_setpoints')->where('variable_id', $variableId)->delete();
            DB::table('variables')->where('id', $variableId)->delete();
        }
        DB::table('variables')->where('key', 'chlorine_dioxide')->update(['sort' => 6]);
    }
};
