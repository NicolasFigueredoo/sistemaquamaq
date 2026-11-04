<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variables con rango (regla "range", p. ej. dióxido de cloro): el setpoint es el mínimo
 * y setpoint_high el máximo. Alarma por baja o por alta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->decimal('default_setpoint_high', 12, 4)->nullable()->after('default_setpoint');
        });

        Schema::table('plant_setpoints', function (Blueprint $table) {
            $table->decimal('setpoint_high', 12, 4)->nullable()->after('setpoint');
        });
    }

    public function down(): void
    {
        Schema::table('plant_setpoints', function (Blueprint $table) {
            $table->dropColumn('setpoint_high');
        });

        Schema::table('variables', function (Blueprint $table) {
            $table->dropColumn('default_setpoint_high');
        });
    }
};
