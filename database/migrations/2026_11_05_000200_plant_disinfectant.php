<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada planta desinfecta con ozono o con dióxido de cloro, nunca con los dos, y ambos entran por el
 * mismo canal. El ozono pasa a tener rango (mínimo y máximo), igual que el dióxido de cloro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plant_settings', function (Blueprint $table) {
            $table->string('disinfectant', 40)->default('ozone')->after('cip_warning_hours');
        });

        // La planta de Exemys es la de prueba con dióxido de cloro.
        $exemys = DB::table('gateways')->where('model', 'Exemys')->pluck('plant_id');
        DB::table('plant_settings')->whereIn('plant_id', $exemys)->update(['disinfectant' => 'chlorine_dioxide']);

        DB::table('variables')->where('key', 'ozone')->update(['rule' => 'range', 'default_setpoint_high' => 1]);
        $ozone = DB::table('variables')->where('key', 'ozone')->value('id');
        if ($ozone) {
            DB::table('plant_setpoints')->where('variable_id', $ozone)->whereNull('setpoint_high')->update(['setpoint_high' => 1]);
        }

        $this->dismissUnused();
    }

    public function down(): void
    {
        DB::table('variables')->where('key', 'ozone')->update(['rule' => 'min', 'default_setpoint_high' => null]);

        Schema::table('plant_settings', function (Blueprint $table) {
            $table->dropColumn('disinfectant');
        });
    }

    /** Estados y alarmas abiertas del desinfectante que cada planta no usa (no deben mover su semáforo). */
    private function dismissUnused(): void
    {
        $now = now();
        $variables = DB::table('variables')->whereIn('key', ['ozone', 'chlorine_dioxide'])->pluck('id', 'key');

        foreach (DB::table('plant_settings')->get(['plant_id', 'disinfectant']) as $settings) {
            $unused = $variables->except($settings->disinfectant)->values();

            DB::table('alarm_events')->where('plant_id', $settings->plant_id)->whereIn('variable_id', $unused)->whereNull('ended_at')
                ->update(['ended_at' => $now, 'duration_s' => 0]);
            DB::table('plant_variable_states')->where('plant_id', $settings->plant_id)->whereIn('variable_id', $unused)->delete();
        }
    }
};
