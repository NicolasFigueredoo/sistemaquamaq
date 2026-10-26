<?php

namespace App\Services\Panel;

use App\Enums\CipStatus;
use App\Enums\PlantStatus;
use App\Enums\VariableState;
use App\Models\AlarmEvent;
use App\Models\CipCycle;
use App\Models\Plant;
use App\Models\User;
use App\Models\Variable;
use App\Services\Telemetry\CipService;
use App\Services\Telemetry\TelemetryIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Arma los datos que consumen las pantallas Vue. Las fechas viajan como epoch en ms
 * y el frontend las formatea en la zona horaria del panel.
 */
class PlantPresenter
{
    public function __construct(private CipService $cip, private TelemetryIngestor $ingestor) {}

    public static function ms($date): ?int
    {
        return $date ? CarbonImmutable::parse($date)->getTimestampMs() : null;
    }

    public function summary(Plant $plant): array
    {
        return [
            'code' => $plant->code,
            'name' => $plant->name,
            'location' => $plant->location,
            'status' => $plant->status->value,
            'lastReadingAt' => self::ms($plant->last_reading_at),
        ];
    }

    public function variables(Plant $plant): array
    {
        $variables = Variable::catalog();
        $setpoints = $this->ingestor->setpointsFor($plant->id, $variables);
        $states = $plant->variableStates()->get()->keyBy('variable_id');

        return $variables->values()->map(function (Variable $v) use ($setpoints, $states) {
            $state = $states->get($v->id);

            return [
                'key' => $v->key,
                'name' => $v->name,
                'shortName' => $v->short_name,
                'alertName' => $v->alert_name,
                'unit' => $v->unit,
                'decimals' => $v->decimals,
                'kind' => $v->kind,
                'rule' => $v->rule,
                'setpoint' => $setpoints[$v->id]->setpoint,
                'setpointMin' => $v->setpoint_min,
                'setpointMax' => $v->setpoint_max,
                'chartMin' => $v->chart_min,
                'chartMax' => $v->chart_max,
                'chartColor' => $v->chart_color,
                'sort' => $v->sort,
                'chartSort' => $v->chart_sort,
                'state' => $state?->state->value ?? 'ok',
                'value' => $state?->last_value,
                'measuredAt' => self::ms($state?->last_measured_at),
                'outSince' => self::ms($state?->out_since),
            ];
        })->all();
    }

    /**
     * Banner de desvío (amarillo/rojo/gris) igual al de la maqueta.
     *
     * @param  array<int, array>  $variables  salida de variables()
     * @param  array<int, array>  $cip  salida de cipStatus()
     */
    public function banner(Plant $plant, array $variables, array $cip): ?array
    {
        if ($plant->status === PlantStatus::Conforme) {
            return null;
        }

        if ($plant->status === PlantStatus::SinConexion) {
            return ['kind' => 'offline', 'variable' => null, 'lastReadingAt' => self::ms($plant->last_reading_at)];
        }

        $kind = $plant->status === PlantStatus::Alarma ? 'alarm' : 'warning';
        $expiredRank = PlantStatus::from(config('telemetry.cip.expired_plant_status', 'alarma'))->rank();

        // Causa que explica el estado de la planta: la de mayor severidad (a igualdad, primero las variables).
        $causes = collect();
        foreach ($variables as $v) {
            if ($v['state'] !== VariableState::Ok->value) {
                $rank = $v['state'] === VariableState::Alarma->value ? PlantStatus::Alarma->rank() : PlantStatus::Atencion->rank();
                $causes->push(['rank' => $rank, 'order' => 0, 'variable' => $v['key'], 'cip' => null]);
            }
        }
        foreach ($cip as $c) {
            $rank = match ($c['status']) {
                CipStatus::Vencido->value => $expiredRank,
                CipStatus::Proximo->value => PlantStatus::Atencion->rank(),
                default => null,
            };
            if ($rank !== null) {
                $causes->push(['rank' => $rank, 'order' => 1, 'variable' => null, 'cip' => $c]);
            }
        }

        $cause = $causes->sortBy([['rank', 'desc'], ['order', 'asc']])->first();

        return ['kind' => $kind, 'variable' => $cause['variable'] ?? null, 'cip' => $cause['cip'] ?? null];
    }

    public function cipStatus(Plant $plant, CarbonImmutable $now): array
    {
        $settings = $plant->settingsOrDefault();
        $equipment = $plant->equipment()->with('lastCompleteCycle')->orderBy('type')->get();

        return $equipment->map(function ($e) use ($settings, $now) {
            $s = $this->cip->statusFor($e, $settings, $now);

            return [
                'type' => $e->type,
                'name' => $e->name,
                'status' => $s['status']->value,
                'statusLabel' => $s['status']->label(),
                'lastCompleteAt' => self::ms($s['last_complete_at']),
                'dueAt' => self::ms($s['due_at']),
            ];
        })->values()->all();
    }

    /** Rangos de los gráficos: [duración, tamaño del promedio en minutos (0 = lecturas crudas), hueco que corta la línea]. */
    private const RANGES = [
        '1h' => ['1 hour', 0, 15],
        '1d' => ['1 day', 10, 30],
        '7d' => ['7 days', 60, 180],
        '30d' => ['30 days', 60, 180],
    ];

    /**
     * Series de los gráficos por rango. 1 h y 1 día salen de las lecturas crudas; 7 y 30 días de los promedios horarios.
     * Cada punto es [epoch ms, valor].
     *
     * @return array{ranges: array<string, array{from: int, to: int, gap: int, series: array<string, list<array{0: int, 1: float}>>}>}
     */
    public function series(Plant $plant, CarbonImmutable $now): array
    {
        $now = $now->utc();
        $charted = Variable::query()->whereNotNull('chart_sort')->orderBy('chart_sort')->get();
        $keys = $charted->pluck('key', 'id');

        $raw = DB::table('readings')
            ->where('plant_id', $plant->id)
            ->whereIn('variable_id', $keys->keys())
            ->where('measured_at', '>=', $now->subDay())
            ->orderBy('measured_at')
            ->get(['variable_id', 'measured_at as at', 'value']);

        $hourly = DB::table('readings_hourly')
            ->where('plant_id', $plant->id)
            ->whereIn('variable_id', $keys->keys())
            ->where('hour', '>=', $now->subDays(30)->startOfHour())
            ->orderBy('hour')
            ->get(['variable_id', 'hour as at', 'avg_value as value']);

        $ranges = [];
        foreach (self::RANGES as $name => [$length, $bucketMin, $gapMin]) {
            $from = $now->sub($length)->getTimestampMs();
            $rows = $bucketMin === 60 ? $hourly : $raw;
            $buckets = [];
            foreach ($rows as $row) {
                $t = CarbonImmutable::parse($row->at, 'UTC')->getTimestampMs();
                if ($t < $from) {
                    continue;
                }
                if ($bucketMin > 0) {
                    $t -= $t % ($bucketMin * 60000);
                }
                $buckets[$keys[$row->variable_id]][$t][] = (float) $row->value;
            }

            $series = [];
            foreach ($charted as $v) {
                $series[$v->key] = [];
                foreach ($buckets[$v->key] ?? [] as $t => $values) {
                    $series[$v->key][] = [$t, round(array_sum($values) / count($values), 4)];
                }
            }

            $ranges[$name] = ['from' => $from, 'to' => $now->getTimestampMs(), 'gap' => $gapMin * 60000, 'series' => $series];
        }

        return ['ranges' => $ranges];
    }

    public function events(Plant $plant, CarbonImmutable $now): array
    {
        $days = config('telemetry.panel.events_days', 30);

        return AlarmEvent::query()
            ->with('variable:id,name')
            ->where('plant_id', $plant->id)
            ->where('started_at', '>=', $now->subDays($days))
            ->orderByDesc('started_at')
            ->limit(500)
            ->get()
            ->map(fn (AlarmEvent $e) => [
                'id' => $e->id,
                'variable' => $e->variable?->name,
                'startedAt' => self::ms($e->started_at),
                'endedAt' => self::ms($e->ended_at),
                'durationS' => $e->ended_at
                    ? $e->duration_s
                    : (int) CarbonImmutable::parse($e->started_at)->diffInSeconds($now),
            ])->all();
    }

    public function note(Plant $plant): ?array
    {
        $note = $plant->auditorNotes()->with('user:id,name')->latest('id')->first();

        return $note ? [
            'body' => $note->body,
            'savedAt' => self::ms($note->created_at),
            'author' => $note->user?->name,
        ] : null;
    }

    public function settings(Plant $plant): array
    {
        $s = $plant->settingsOrDefault();

        return [
            'cipPeriodDays' => $s->cip_period_days,
            'cipWarningHours' => $s->cip_warning_hours,
        ];
    }

    public function cipHistory(Plant $plant, CarbonImmutable $now): array
    {
        $months = config('telemetry.panel.cip_history_months', 13);

        return CipCycle::query()
            ->with(['stages', 'equipment:id,name,type'])
            ->whereHas('equipment', fn ($q) => $q->where('plant_id', $plant->id))
            ->where('finished_at', '>=', $now->subMonths($months))
            ->orderByDesc('finished_at')
            ->get()
            ->map(fn (CipCycle $c) => [
                'id' => $c->id,
                'finishedAt' => self::ms($c->finished_at),
                'equipment' => $c->equipment->name,
                'result' => $c->result,
                'totalMin' => $c->total_min,
                'stages' => $c->stages->map(fn ($s) => [
                    'stage' => $s->stage,
                    'label' => CipCycle::STAGE_LABELS[$s->stage] ?? $s->stage,
                    'minutes' => $s->duration_min,
                    'completed' => $s->completed,
                ])->values()->all(),
            ])->all();
    }

    public function permissions(User $user): array
    {
        return [
            'configure' => $user->isAdmin(),
            'writeNotes' => $user->canWriteNotes(),
        ];
    }
}
