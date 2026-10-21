<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gateway;
use App\Services\Telemetry\TelemetryIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alternativa a MQTT: el EM300 (nodo "HTTP Request" de Node-RED) hace
 *   POST /api/ingest/telemetry   Authorization: Bearer <token del gateway>
 *   POST /api/ingest/cip
 * con el mismo JSON que publicaría por MQTT.
 */
class IngestController extends Controller
{
    public function __construct(private TelemetryIngestor $ingestor) {}

    public function telemetry(Request $request): JsonResponse
    {
        $gateway = $this->gateway($request);
        $receivedAt = CarbonImmutable::now();

        try {
            $count = $this->ingestor->telemetry($gateway, $request->getContent(), $receivedAt);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'stored' => $count]);
    }

    public function cip(Request $request): JsonResponse
    {
        $gateway = $this->gateway($request);

        try {
            $this->ingestor->cipCycle($gateway, $request->getContent(), CarbonImmutable::now());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    private function gateway(Request $request): Gateway
    {
        $token = (string) $request->bearerToken();
        abort_if($token === '', 401, 'Falta el token del gateway.');

        $gateway = Gateway::where('api_token_hash', Gateway::hashToken($token))->first();
        abort_unless($gateway, 401, 'Token inválido.');

        return $gateway;
    }
}
