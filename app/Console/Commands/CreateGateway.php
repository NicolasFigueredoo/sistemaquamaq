<?php

namespace App\Console\Commands;

use App\Models\Gateway;
use App\Models\Plant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateGateway extends Command
{
    protected $signature = 'telemetry:gateway {plant : código de la planta (slug)} {serial : identificador del EM300, va en el topic} {--rotate-token : genera un token HTTP nuevo}';

    protected $description = 'Da de alta (o actualiza) un gateway EM300 para una planta y muestra cómo configurarlo.';

    public function handle(): int
    {
        $plant = Plant::where('code', $this->argument('plant'))->first();
        if (! $plant) {
            $this->error('No existe una planta con código '.$this->argument('plant'));

            return self::FAILURE;
        }

        $serial = Str::upper($this->argument('serial'));
        $gateway = Gateway::firstOrNew(['serial' => $serial]);
        $gateway->plant_id = $plant->id;
        $gateway->mqtt_username ??= $serial; // igual al serial: la ACL de Mosquitto usa %u en el topic

        $token = null;
        if (! $gateway->api_token_hash || $this->option('rotate-token')) {
            $token = Str::random(48);
            $gateway->api_token_hash = Gateway::hashToken($token);
        }
        $gateway->save();

        $prefix = config('telemetry.mqtt.topic_prefix');
        $this->info("Gateway {$serial} → {$plant->name}");
        $this->table(['Dato', 'Valor'], [
            ['Topic telemetría', "{$prefix}/{$serial}/telemetry"],
            ['Topic CIP', "{$prefix}/{$serial}/cip"],
            ['Topic presencia (Last Will)', "{$prefix}/{$serial}/status"],
            ['Usuario MQTT sugerido', $gateway->mqtt_username],
            ['Token HTTP (Bearer)', $token ?? '(sin cambios — usar --rotate-token para generar uno nuevo)'],
        ]);
        if ($token) {
            $this->warn('Guardá el token ahora: no se vuelve a mostrar.');
        }

        return self::SUCCESS;
    }
}
