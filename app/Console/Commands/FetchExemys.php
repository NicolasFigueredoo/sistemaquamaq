<?php

namespace App\Console\Commands;

use App\Services\Exemys\ExemysClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Consulta la API de Exemys M2M y muestra los valores. Por ahora solo lectura (no guarda en la base).
 */
class FetchExemys extends Command
{
    protected $signature = 'exemys:fetch
        {--unit= : unitID de la plataforma (por defecto EXEMYS_UNIT_ID)}
        {--history : Trae históricos en lugar de valores actuales}
        {--hours=24 : Horas hacia atrás para --history}
        {--type=T : Tipo de histórico: T, R, M, I, L, B}
        {--io= : Solo este ioID (con --history)}
        {--json : Mostrar la respuesta como JSON}';

    protected $description = 'Trae valores actuales o históricos de una unidad Exemys M2M.';

    public function handle(): int
    {
        $unitId = (int) ($this->option('unit') ?: config('telemetry.exemys.unit_id'));
        if ($unitId <= 0) {
            $this->error('Falta el unitID: pasá --unit o definí EXEMYS_UNIT_ID.');

            return self::FAILURE;
        }

        try {
            $client = ExemysClient::fromConfig();

            if ($this->option('history')) {
                $until = CarbonImmutable::now();
                $data = $client->historyValues(
                    $unitId,
                    $until->subHours(max(1, (int) $this->option('hours'))),
                    $until,
                    strtoupper((string) $this->option('type')),
                    $this->option('io') ? (int) $this->option('io') : null,
                );
            } else {
                $data = $client->currentValues($unitId);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->option('history') ? $this->printHistory($data) : $this->printCurrent($data);

        return self::SUCCESS;
    }

    private function printCurrent(array $data): void
    {
        $this->info(sprintf(
            '%s (unit %d) - %s - último reporte hace %s',
            $data['desc'], $data['unit_id'], $data['online'] ? 'EN LÍNEA' : 'DESCONECTADO', $data['last_report'],
        ));
        $this->table(
            ['ioID', 'Descripción', 'Valor', 'Unidad'],
            array_map(fn ($io) => [$io['id'], $io['desc'], $io['value'] ?? '-', $io['unit']], $data['ios']),
        );
    }

    private function printHistory(array $data): void
    {
        $this->info(sprintf('%s (unit %d) - %d registros', $data['desc'], $data['unit_id'], count($data['records'])));
        $this->table(
            array_merge(['Fecha'], array_column($data['ios'], 'desc')),
            array_map(fn ($r) => array_merge(
                [$r['date']->format('Y-m-d H:i:s')],
                array_map(
                    fn ($io) => ($r['values'][$io['id']] ?? '-').(in_array($io['id'], $r['flagged'], true) ? '*' : ''),
                    $data['ios'],
                ),
            ), $data['records']),
        );
    }
}
