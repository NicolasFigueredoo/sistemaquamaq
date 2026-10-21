<?php

namespace App\Console\Commands;

use App\Models\Gateway;
use App\Models\MqttInbox;
use App\Services\Telemetry\TelemetryIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReprocessInbox extends Command
{
    protected $signature = 'telemetry:reprocess {--limit=1000}';

    protected $description = 'Reintenta los mensajes MQTT que quedaron con error (p. ej. gateway dado de alta después).';

    public function handle(TelemetryIngestor $ingestor): int
    {
        $ok = 0;
        $failed = 0;
        MqttInbox::query()->whereNull('processed_at')->orderBy('id')->limit((int) $this->option('limit'))->get()
            ->each(function (MqttInbox $inbox) use ($ingestor, &$ok, &$failed) {
                $parts = explode('/', trim($inbox->topic, '/'));
                $serial = $parts[count($parts) - 2] ?? null;
                $gateway = $serial ? Gateway::where('serial', $serial)->first() : null;
                if ($gateway && ! $inbox->gateway_id) {
                    $inbox->gateway_id = $gateway->id;
                }
                $ingestor->process($inbox, $gateway, (string) end($parts), CarbonImmutable::parse($inbox->received_at));
                $inbox->processed_at ? $ok++ : $failed++;
            });

        $this->info("Procesados: {$ok} · Con error: {$failed}");

        return self::SUCCESS;
    }
}
