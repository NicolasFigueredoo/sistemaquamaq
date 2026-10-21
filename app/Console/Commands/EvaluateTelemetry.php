<?php

namespace App\Console\Commands;

use App\Models\Gateway;
use App\Models\Plant;
use App\Services\Telemetry\GatewayPresence;
use App\Services\Telemetry\PlantStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class EvaluateTelemetry extends Command
{
    protected $signature = 'telemetry:evaluate';

    protected $description = 'Marca gateways sin conexión, evalúa vencimientos CIP y recalcula el estado de cada planta (correr cada minuto).';

    public function handle(GatewayPresence $presence, PlantStatusService $status): int
    {
        $now = CarbonImmutable::now();
        $offline = 0;

        Gateway::query()->where('online', true)->with('plant.settings')->each(function (Gateway $gateway) use ($presence, $now, &$offline) {
            $minutes = $gateway->plant?->settings?->offline_after_minutes ?? 10;
            if (! $gateway->last_seen_at || CarbonImmutable::parse($gateway->last_seen_at)->addMinutes($minutes)->lessThan($now)) {
                $presence->offline($gateway, $gateway->last_seen_at ? CarbonImmutable::parse($gateway->last_seen_at) : $now);
                $offline++;
            }
        });

        $changed = 0;
        Plant::query()->where('active', true)->each(function (Plant $plant) use ($status, $now, &$changed) {
            $before = $plant->status;
            if ($status->refresh($plant, $now) !== $before) {
                $changed++;
            }
        });

        $this->info("Gateways marcados sin conexión: {$offline} · Plantas con cambio de estado: {$changed}");

        return self::SUCCESS;
    }
}
