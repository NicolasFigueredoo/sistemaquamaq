<?php

namespace App\Services\Exemys;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de la API REST de Exemys M2M (currentValues / historyValues).
 *
 * Particularidades de la API real (no están en el PDF):
 *  - Con credenciales malas responde HTTP 200 con el texto "Auth Error".
 *  - Todos los números llegan como string ("18.9", "1").
 *  - En el histórico un valor puede venir con "*" al final ("53.0*"); se lo marca como flagged.
 */
class ExemysClient
{
    public const TYPE_INSTANT = 'T';
    public const TYPE_AVERAGE = 'R';
    public const TYPE_MAX = 'M';
    public const TYPE_MIN = 'I';
    public const TYPE_ALARM = 'L';
    public const TYPE_INSTANT_ALARM = 'B';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 20,
        private readonly ?string $caBundle = null,
    ) {}

    public static function fromConfig(): self
    {
        $cfg = config('telemetry.exemys');

        return new self($cfg['base_url'], $cfg['username'], $cfg['password'], $cfg['timeout'], $cfg['ca_bundle']);
    }

    /**
     * @return array{unit_id: int, desc: string, online: bool, last_report: string, ios: list<array{id: int, desc: string, value: ?float, unit: string}>}
     */
    public function currentValues(int $unitId): array
    {
        $data = $this->get('currentValues.php', ['unitID' => $unitId]);

        return [
            'unit_id' => (int) ($data['unitID'] ?? $unitId),
            'desc' => (string) ($data['unitDesc'] ?? ''),
            'online' => (int) ($data['linkState'] ?? 0) === 1,
            'last_report' => (string) ($data['lastReport'] ?? ''),
            'ios' => array_map(fn (array $io) => [
                'id' => (int) $io['id'],
                'desc' => (string) ($io['desc'] ?? ''),
                'value' => $this->number($io['val'] ?? null)[0],
                'unit' => (string) ($io['unit'] ?? ''),
            ], $data['ios'] ?? []),
        ];
    }

    /**
     * Fechas en hora local del servidor Exemys (Argentina). Devuelve una fila por registro
     * con los valores ya asociados al id de cada IO.
     *
     * @return array{unit_id: int, desc: string, ios: list<array{id: int, desc: string, unit: string}>, records: list<array{date: CarbonImmutable, values: array<int, ?float>, flagged: list<int>}>}
     */
    public function historyValues(
        int $unitId,
        CarbonImmutable $since,
        CarbonImmutable $until,
        string $type = self::TYPE_INSTANT,
        ?int $ioId = null,
    ): array {
        $tz = config('telemetry.gateway_timezone');
        $query = [
            'unitID' => $unitId,
            'since' => $since->setTimezone($tz)->format('Y/m/d H:i:s'),
            'until' => $until->setTimezone($tz)->format('Y/m/d H:i:s'),
            'type' => $type,
        ];
        if ($ioId !== null) {
            $query['ioID'] = $ioId;
        }

        $data = $this->get('historyValues.php', $query);

        $ios = array_map(fn (array $io) => [
            'id' => (int) $io['id'],
            'desc' => (string) ($io['desc'] ?? ''),
            'unit' => (string) ($io['unit'] ?? ''),
        ], $data['ios'] ?? []);
        $ids = array_column($ios, 'id');

        $records = [];
        foreach ($data['records'] ?? [] as $row) {
            $values = [];
            $flagged = [];
            foreach (explode(',', (string) $row['values']) as $i => $raw) {
                if (! isset($ids[$i])) {
                    continue;
                }
                [$value, $flag] = $this->number($raw);
                $values[$ids[$i]] = $value;
                if ($flag) {
                    $flagged[] = $ids[$i];
                }
            }
            $records[] = [
                'date' => CarbonImmutable::parse($row['date'], $tz),
                'values' => $values,
                'flagged' => $flagged,
            ];
        }

        return [
            'unit_id' => (int) ($data['unitID'] ?? $unitId),
            'desc' => (string) ($data['unitDesc'] ?? ''),
            'ios' => $ios,
            'records' => $records,
        ];
    }

    private function get(string $endpoint, array $query): array
    {
        $response = $this->http()->get($endpoint, $query);
        $body = trim($response->body());

        if ($response->status() === 401 || $body === 'Auth Error') {
            throw new RuntimeException('Exemys: credenciales incorrectas.');
        }
        if (! $response->successful()) {
            throw new RuntimeException("Exemys {$endpoint}: HTTP {$response->status()} - {$body}");
        }

        $data = json_decode($body, true);
        if (! is_array($data)) {
            throw new RuntimeException("Exemys {$endpoint}: respuesta inesperada - ".mb_substr($body, 0, 200));
        }

        return $data;
    }

    private function http(): PendingRequest
    {
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('Exemys: faltan EXEMYS_USERNAME / EXEMYS_PASSWORD en el .env.');
        }

        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->when($this->caBundle, fn (PendingRequest $http) => $http->withOptions(['verify' => $this->caBundle]))
            ->withBasicAuth($this->username, $this->password)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(2, 1000, throw: false);
    }

    /** @return array{0: ?float, 1: bool} valor y si venía marcado con "*" */
    private function number(mixed $raw): array
    {
        $raw = trim((string) $raw);
        $flag = str_ends_with($raw, '*');
        $raw = rtrim($raw, '*');

        return [is_numeric($raw) ? (float) $raw : null, $flag];
    }
}
