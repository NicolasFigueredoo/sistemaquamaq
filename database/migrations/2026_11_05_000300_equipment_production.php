<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Señal "en producción" de cada equipo (null = el gateway no la informa). Mientras un equipo está
 * detenido no se registran sus variables. La conductividad se muestra con un decimal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->boolean('producing')->nullable()->after('name');
            $table->timestamp('producing_at')->nullable()->after('producing');
        });

        DB::table('variables')->where('key', 'alkaline')->update(['decimals' => 1]);
    }

    public function down(): void
    {
        DB::table('variables')->where('key', 'alkaline')->update(['decimals' => 2]);

        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn(['producing', 'producing_at']);
        });
    }
};
