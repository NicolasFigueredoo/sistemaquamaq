<?php

namespace App\Http\Controllers;

use App\Console\Commands\SyncExemys;
use App\Models\Plant;
use App\Services\Exemys\ExemysClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Botón de prueba del panel: lo mismo que `php artisan exemys:fetch`, consultado en el momento. */
class ExemysTestController extends Controller
{
    public function __invoke(Request $request, Plant $plant): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && $request->user()->canSeePlant($plant), 403);

        $serial = $plant->gateways()->where('model', SyncExemys::GATEWAY_MODEL)->value('serial');
        abort_unless($serial, 404, 'Esta planta no está vinculada a Exemys.');

        $unitId = (int) str_replace('EXEMYS-', '', $serial);
        $started = microtime(true);

        try {
            $data = ExemysClient::fromConfig()->currentValues($unitId);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($data + [
            'mapped' => array_map('strval', array_keys(config('telemetry.exemys.io_map'))),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
