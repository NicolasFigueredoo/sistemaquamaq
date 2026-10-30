<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIP armado a partir de las marcas del PLC (1 = etapa corriendo, 0 = terminada):
 * el ciclo se abre en el servidor y queda "en_curso" (sin finished_at) hasta que cierra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cip_cycles', function (Blueprint $table) {
            $table->dateTime('finished_at')->nullable()->change();
        });

        Schema::table('cip_cycle_stages', function (Blueprint $table) {
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
        });

        // Último estado conocido de cada marca, para detectar flancos entre corridas del sync.
        Schema::create('cip_stage_states', function (Blueprint $table) {
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('stage', 20);
            $table->boolean('active')->default(false);
            $table->dateTime('changed_at')->nullable();
            $table->dateTime('last_sample_at')->nullable();
            $table->primary(['equipment_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cip_stage_states');

        Schema::table('cip_cycle_stages', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'ended_at']);
        });
    }
};
