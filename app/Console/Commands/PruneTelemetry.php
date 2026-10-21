<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTelemetry extends Command
{
    protected $signature = 'telemetry:prune';

    protected $description = 'Borra lecturas crudas, promedios y mensajes MQTT más viejos que la retención configurada.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $r = config('telemetry.retention');

        $raw = $this->chunkedDelete('readings', 'measured_at', $now->subDays($r['raw_days']));
        $hourly = DB::table('readings_hourly')->where('hour', '<', $now->subDays($r['hourly_days']))->delete();
        $inbox = $this->chunkedDelete('mqtt_inbox', 'received_at', $now->subDays($r['inbox_days']));

        $this->info("Lecturas: {$raw} · Promedios horarios: {$hourly} · Mensajes MQTT: {$inbox}");

        return self::SUCCESS;
    }

    private function chunkedDelete(string $table, string $column, CarbonImmutable $before): int
    {
        $total = 0;
        do {
            $ids = DB::table($table)->where($column, '<', $before)->limit(5000)->pluck('id');
            $deleted = $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
