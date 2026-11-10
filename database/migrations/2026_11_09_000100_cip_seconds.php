<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CIP en segundos: duración de cada etapa y total del ciclo. Además, el motivo por el que un
 * ciclo se cerró sin terminar (nuevo saneado, marca de proceso cortada o inactividad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cip_cycle_stages', function (Blueprint $table) {
            $table->unsignedInteger('duration_s')->nullable()->after('duration_min');
        });
        Schema::table('cip_cycles', function (Blueprint $table) {
            $table->unsignedInteger('total_s')->default(0)->after('total_min');
            $table->string('close_reason', 20)->nullable()->after('result');
        });

        // Lo ya registrado: con inicio y fin se calcula exacto; si no, desde los minutos.
        DB::table('cip_cycle_stages')->whereNotNull('duration_min')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $seconds = $row->started_at && $row->ended_at
                    ? max(0, (int) CarbonImmutable::parse($row->started_at)->diffInSeconds(CarbonImmutable::parse($row->ended_at)))
                    : (int) $row->duration_min * 60;
                DB::table('cip_cycle_stages')->where('id', $row->id)->update(['duration_s' => $seconds]);
            }
        });

        $totals = DB::table('cip_cycle_stages')->where('completed', true)
            ->groupBy('cip_cycle_id')->select('cip_cycle_id', DB::raw('SUM(duration_s) as total'))->pluck('total', 'cip_cycle_id');
        foreach ($totals as $cycleId => $total) {
            DB::table('cip_cycles')->where('id', $cycleId)->update(['total_s' => (int) $total]);
        }
    }

    public function down(): void
    {
        Schema::table('cip_cycles', function (Blueprint $table) {
            $table->dropColumn(['total_s', 'close_reason']);
        });
        Schema::table('cip_cycle_stages', function (Blueprint $table) {
            $table->dropColumn('duration_s');
        });
    }
};
