<?php

namespace App\Services\Telemetry;

use App\Enums\CipStatus;
use App\Models\CipCycle;
use App\Models\Equipment;
use App\Models\Gateway;
use App\Models\PlantSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CipService
{
    /** Ciclo armado desde las marcas del PLC que todavía no cerró (sin finished_at). */
    public const RUNNING = 'en_curso';

    /**
     * Estado de vigencia de un equipo: el vencimiento es el último CIP *finalizado* + N días,
     * a la misma hora. "Próximo a vencer" durante las últimas cip_warning_hours.
     *
     * @return array{status: CipStatus, last_complete_at: ?CarbonImmutable, due_at: ?CarbonImmutable}
     */
    public function statusFor(Equipment $equipment, PlantSetting $settings, CarbonImmutable $now): array
    {
        $last = $equipment->relationLoaded('lastCompleteCycle')
            ? $equipment->lastCompleteCycle
            : $equipment->lastCompleteCycle()->first();

        if (! $last) {
            return ['status' => CipStatus::SinRegistro, 'last_complete_at' => null, 'due_at' => null];
        }

        $lastAt = CarbonImmutable::parse($last->finished_at);
        $due = $lastAt->addDays($settings->cip_period_days);

        $status = match (true) {
            $now->greaterThanOrEqualTo($due) => CipStatus::Vencido,
            $now->greaterThanOrEqualTo($due->subHours($settings->cip_warning_hours)) => CipStatus::Proximo,
            default => CipStatus::Vigente,
        };

        return ['status' => $status, 'last_complete_at' => $lastAt, 'due_at' => $due];
    }

    /**
     * Registra un ciclo informado por el PLC. Idempotente por (equipo, cycle_id).
     *
     * @param  array{cycle_id: string, equipment: string, started_at: ?CarbonImmutable, finished_at: CarbonImmutable, stages: list<array>}  $data
     */
    public function record(Gateway $gateway, array $data): CipCycle
    {
        return DB::transaction(function () use ($gateway, $data) {
            $equipment = $this->equipmentFor($gateway->plant_id, $data['equipment']);

            $existing = CipCycle::where('equipment_id', $equipment->id)->where('plc_cycle_id', $data['cycle_id'])->first();
            if ($existing) {
                return $existing;
            }

            $cycle = CipCycle::create([
                'equipment_id' => $equipment->id,
                'plc_cycle_id' => $data['cycle_id'],
                'started_at' => $data['started_at'],
                'finished_at' => $data['finished_at'],
            ] + $this->outcome($data['stages']));

            foreach ($data['stages'] as $stage) {
                $cycle->stages()->create($stage);
            }

            return $cycle;
        });
    }

    /**
     * Una muestra de la marca de una etapa en el PLC (1 = corriendo, 0 = terminada), con la hora
     * que le puso el módulo. Arma el ciclo por flancos:
     *  - 0→1 de saneado abre un ciclo nuevo (si había otro abierto, lo cierra incompleto);
     *  - 0→1 de otra etapa sin ciclo abierto abre uno igual (le va a faltar el saneado);
     *  - 1→0 cierra la etapa, y si es el enjuague final, cierra el ciclo.
     * Las muestras viejas o repetidas (el sync se solapa con la corrida anterior) se ignoran.
     *
     * @return bool si cambió algún ciclo
     */
    public function signal(Gateway $gateway, string $equipmentType, string $stage, bool $on, CarbonImmutable $at): bool
    {
        if (! in_array($stage, CipCycle::STAGES, true)) {
            throw new InvalidArgumentException("Etapa CIP desconocida: '{$stage}'");
        }
        $at = $at->utc()->startOfSecond();

        return DB::transaction(function () use ($gateway, $equipmentType, $stage, $on, $at) {
            $equipment = $this->equipmentFor($gateway->plant_id, $equipmentType);
            $key = ['equipment_id' => $equipment->id, 'stage' => $stage];
            $state = DB::table('cip_stage_states')->where($key)->lockForUpdate()->first();

            if (! $state) {
                // Primera muestra de esta marca: no vimos el flanco, solo se toma el estado.
                DB::table('cip_stage_states')->insert($key + ['active' => $on, 'changed_at' => $at, 'last_sample_at' => $at]);

                return false;
            }
            if ($state->last_sample_at && $at->lessThanOrEqualTo(CarbonImmutable::parse($state->last_sample_at))) {
                return false;
            }
            if ((bool) $state->active === $on) {
                DB::table('cip_stage_states')->where($key)->update(['last_sample_at' => $at]);

                return false;
            }

            DB::table('cip_stage_states')->where($key)->update(['active' => $on, 'changed_at' => $at, 'last_sample_at' => $at]);

            return $on ? $this->stageStarted($equipment, $stage, $at) : $this->stageEnded($equipment, $stage, $at);
        });
    }

    /** Cierra como incompletos los ciclos abiertos sin ninguna etapa activa desde hace cip.stale_minutes. */
    public function closeStale(CarbonImmutable $now): int
    {
        $limit = $now->subMinutes((int) config('telemetry.cip.stale_minutes', 120));
        $closed = 0;

        CipCycle::query()->where('result', self::RUNNING)->each(function (CipCycle $cycle) use ($limit, &$closed) {
            $states = DB::table('cip_stage_states')->where('equipment_id', $cycle->equipment_id)->get();
            if ($states->contains(fn ($s) => (bool) $s->active)) {
                return;
            }

            $last = collect([$cycle->started_at, ...$states->pluck('changed_at')])
                ->filter()
                ->map(fn ($d) => CarbonImmutable::parse($d))
                ->max();
            if ($last->greaterThan($limit)) {
                return;
            }

            DB::transaction(fn () => $this->close($cycle, $last));
            $closed++;
        });

        return $closed;
    }

    /**
     * Etapa activa por equipo según las marcas del PLC.
     *
     * @param  list<int>  $equipmentIds
     * @return array<int, array{stage: string, since: CarbonImmutable}> indexado por equipment_id
     */
    public function running(array $equipmentIds): array
    {
        return DB::table('cip_stage_states')
            ->whereIn('equipment_id', $equipmentIds)
            ->where('active', true)
            ->orderBy('changed_at')
            ->get()
            ->mapWithKeys(fn ($s) => [$s->equipment_id => ['stage' => $s->stage, 'since' => CarbonImmutable::parse($s->changed_at)]])
            ->all();
    }

    private function stageStarted(Equipment $equipment, string $stage, CarbonImmutable $at): bool
    {
        $open = $this->openCycle($equipment);
        if ($open && $stage === CipCycle::STAGES[0]) {
            $this->close($open, $at);
            $open = null;
        }
        $open ??= CipCycle::create([
            'equipment_id' => $equipment->id,
            'plc_cycle_id' => 'AUTO-'.$at->format('YmdHis'),
            'started_at' => $at,
            'finished_at' => null,
            'total_min' => 0,
            'result' => self::RUNNING,
        ]);

        $open->stages()->updateOrCreate(
            ['stage' => $stage],
            [
                'position' => array_search($stage, CipCycle::STAGES, true) + 1,
                'started_at' => $at,
                'ended_at' => null,
                'duration_min' => null,
                'completed' => false,
            ],
        );

        return true;
    }

    private function stageEnded(Equipment $equipment, string $stage, CarbonImmutable $at): bool
    {
        $open = $this->openCycle($equipment);
        if (! $open) {
            return false;
        }

        // Si no vimos arrancar la etapa no se sabe cuánto duró: queda sin cumplir.
        $row = $open->stages()->where('stage', $stage)->whereNull('ended_at')->first();
        $row?->update([
            'ended_at' => $at,
            'duration_min' => (int) round(CarbonImmutable::parse($row->started_at)->diffInSeconds($at) / 60),
            'completed' => true,
        ]);

        if ($stage === CipCycle::STAGES[count(CipCycle::STAGES) - 1]) {
            $this->close($open, $at);
        }

        return true;
    }

    /** Completa las etapas que faltan como no cumplidas y deja el resultado final. */
    private function close(CipCycle $cycle, CarbonImmutable $at): void
    {
        $existing = $cycle->stages()->get()->keyBy('stage');
        foreach (CipCycle::STAGES as $i => $name) {
            $row = $existing->get($name);
            if (! $row) {
                $cycle->stages()->create(['stage' => $name, 'position' => $i + 1, 'duration_min' => null, 'completed' => false]);
            } elseif (! $row->ended_at) {
                $row->update(['completed' => false]); // seguía corriendo cuando se cerró el ciclo
            }
        }

        $cycle->update(['finished_at' => $at] + $this->outcome($cycle->stages()->get()->toArray()));
    }

    private function openCycle(Equipment $equipment): ?CipCycle
    {
        return CipCycle::where('equipment_id', $equipment->id)->where('result', self::RUNNING)->latest('started_at')->first();
    }

    /** @return array{total_min: int, result: string} */
    private function outcome(array $stages): array
    {
        $stages = collect($stages);

        return [
            'total_min' => (int) $stages->where('completed', true)->sum('duration_min'),
            'result' => $stages->every(fn ($s) => $s['completed']) ? 'finalizado' : 'incompleto',
        ];
    }

    private function equipmentFor(int $plantId, string $type): Equipment
    {
        return Equipment::firstOrCreate(['plant_id' => $plantId, 'type' => $type], ['name' => ucfirst($type)]);
    }
}
