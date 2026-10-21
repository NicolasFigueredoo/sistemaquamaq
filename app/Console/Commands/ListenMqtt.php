<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetryIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Proceso permanente que se suscribe al broker y guarda todo lo que publican los gateways.
 * En producción correrlo con Supervisor (Linux) o NSSM / Programador de tareas (Windows).
 */
class ListenMqtt extends Command
{
    protected $signature = 'telemetry:listen {--once : Procesa lo que haya y termina (útil para pruebas)}';

    protected $description = 'Se suscribe al broker MQTT e ingesta telemetría, ciclos CIP y presencia de los gateways.';

    private bool $stop = false;

    public function handle(TelemetryIngestor $ingestor): int
    {
        $cfg = config('telemetry.mqtt');
        $prefix = trim($cfg['topic_prefix'], '/');

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        $attempt = 0;
        while (! $this->stop) {
            try {
                $client = new MqttClient($cfg['host'], $cfg['port'], $cfg['client_id'], MqttClient::MQTT_3_1_1);
                $settings = (new ConnectionSettings)
                    ->setUsername($cfg['username'])
                    ->setPassword($cfg['password'])
                    ->setKeepAliveInterval($cfg['keep_alive'])
                    ->setConnectTimeout(10)
                    ->setSocketTimeout(5)
                    ->setUseTls($cfg['tls']);
                if ($cfg['tls'] && $cfg['tls_ca_file']) {
                    $settings = $settings->setTlsCertificateAuthorityFile($cfg['tls_ca_file']);
                }

                $client->connect($settings, $cfg['clean_session']);
                $attempt = 0;
                $this->info("Conectado a {$cfg['host']}:{$cfg['port']} como {$cfg['client_id']}. Escuchando {$prefix}/+/telemetry|cip|status");

                foreach (['telemetry', 'cip', 'status'] as $kind) {
                    $client->subscribe("{$prefix}/+/{$kind}", function (string $topic, string $message) use ($ingestor) {
                        $this->handleMessage($ingestor, $topic, $message);
                    }, $cfg['qos']);
                }

                $started = microtime(true);
                while (! $this->stop) {
                    $client->loopOnce(microtime(true), true);
                    if ($this->option('once') && microtime(true) - $started > 3) {
                        $this->stop = true;
                    }
                }

                $client->disconnect();
            } catch (\Throwable $e) {
                $attempt++;
                $wait = min(60, 2 ** min($attempt, 6));
                $this->error("MQTT: {$e->getMessage()} — reintento en {$wait}s");
                report($e);
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep($wait);
            }
        }

        return self::SUCCESS;
    }

    private function handleMessage(TelemetryIngestor $ingestor, string $topic, string $message): void
    {
        try {
            DB::reconnect(); // conexiones largas: evita "MySQL server has gone away"
            $inbox = $ingestor->handleMqtt($topic, $message, CarbonImmutable::now());
            if ($inbox->error) {
                $this->warn("✗ {$topic}: {$inbox->error}");
            } elseif ($this->output->isVerbose()) {
                $this->line("✓ {$topic}");
            }
        } catch (\Throwable $e) {
            $this->error("✗ {$topic}: {$e->getMessage()}");
            report($e);
        }
    }
}
