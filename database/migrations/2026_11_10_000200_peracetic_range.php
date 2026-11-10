<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Ácido peracético: el sensor mide de 0 a 2000 ppm (rango del ajuste y del gráfico). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('variables')->where('key', 'peracetic')->update([
            'decimals' => 1, 'setpoint_max' => 2000, 'chart_min' => 0, 'chart_max' => 2000,
        ]);
    }

    public function down(): void
    {
        DB::table('variables')->where('key', 'peracetic')->update([
            'decimals' => 2, 'setpoint_max' => 100, 'chart_min' => 0, 'chart_max' => 1,
        ]);
    }
};
