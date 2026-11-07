<?php

namespace App\Services\Telemetry;

use App\Models\ConnectionEvent;
use App\Models\Gateway;
use Carbon\CarbonImmutable;

class GatewayPresence
{
    public function __construct(private VariableEvaluator $evaluator) {}

    /** El gateway habló: queda online y se cierra el corte abierto, si lo había. */
    public function seen(Gateway $gateway, CarbonImmutable $at): void
    {
        if (! $gateway->online) {
            $open = ConnectionEvent::where('gateway_id', $gateway->id)->whereNull('online_at')->latest('offline_at')->first();
            if ($open) {
                $open->online_at = $at;
                $open->duration_s = max(0, (int) CarbonImmutable::parse($open->offline_at)->diffInSeconds($at));
                $open->save();
            }
        }

        $gateway->online = true;
        if (! $gateway->last_seen_at || $at->greaterThan($gateway->last_seen_at)) {
            $gateway->last_seen_at = $at;
        }
        $gateway->save();
    }

    /**
     * Marca el gateway sin conexión desde $since (normalmente su último mensaje). Si la planta se
     * queda sin ningún gateway en línea se cierran sus alarmas: no se registran durante el corte.
     */
    public function offline(Gateway $gateway, CarbonImmutable $since): void
    {
        if (! $gateway->online) {
            return;
        }

        $gateway->online = false;
        $gateway->save();

        ConnectionEvent::create(['gateway_id' => $gateway->id, 'offline_at' => $since]);

        if (! Gateway::where('plant_id', $gateway->plant_id)->where('online', true)->exists()) {
            $this->evaluator->release($gateway->plant_id, $since);
        }
    }
}
