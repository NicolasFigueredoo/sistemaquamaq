<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Decimales como los entrega Exemys: conductividad con dos (8.76 mS/cm) y ácido peracético
 * en números enteros (260 ppm). Los setpoints de peracético se redondean; el mínimo de fábrica
 * (0,1 ppm) pasa a 1 ppm para que la alarma por baja siga teniendo sentido.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('variables')->where('key', 'alkaline')->update(['decimals' => 2]);
        DB::table('variables')->where('key', 'peracetic')->update(['decimals' => 0, 'default_setpoint' => 1]);

        $peracetic = DB::table('variables')->where('key', 'peracetic')->value('id');
        if ($peracetic) {
            foreach (DB::table('plant_setpoints')->where('variable_id', $peracetic)->get(['plant_id', 'setpoint']) as $row) {
                DB::table('plant_setpoints')->where('variable_id', $peracetic)->where('plant_id', $row->plant_id)
                    ->update(['setpoint' => max(1, round((float) $row->setpoint))]);
            }
        }
    }

    public function down(): void
    {
        DB::table('variables')->where('key', 'alkaline')->update(['decimals' => 1]);
        DB::table('variables')->where('key', 'peracetic')->update(['decimals' => 1, 'default_setpoint' => 0.1]);
    }
};
