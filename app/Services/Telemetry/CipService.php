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

    /** Marca del PLC "proceso CIP pendiente" (cip_hold_map): se guarda junto a las de etapa. */
    public const HOLD = 'proceso';

    /** Por qué se cerró un ciclo sin terminar (cip_cycles.close_reason). */
    public const REASON_NEW_CYCLE = 'nuevo_saneado';

    public const REASON_HOLD_OFF = 'marca_cortada';

    public const REASON_STALE = 'sin_actividad';

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

            $stages = array_map(fn (array $s) => $s + [
                'duration_s' => isset($s['duration_min']) ? (int) $s['duration_min'] * 60 : null,
            ], $data['stages']);

            $cycle = CipCycle::create([
                'equipment_id' => $equipment->id,
                'plc_cycle_id' => $data['cycle_id'],
                'started_at' => $data['started_at'],
                'finished_at' => $data['finished_at'],
            ] + $this->outcome($stages));

            foreach ($stages as $stage) {
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
     * Entre una etapa y la siguiente el ciclo queda pendiente sin límite de tiempo: se puede dejar
     * el enjuague final para el día siguiente.
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
            if (! $this->edge($equipment, $stage, $on, $at)) {
                return false;
            }

            return $on ? $this->stageStarted($equipment, $stage, $at) : $this->stageEnded($equipment, $stage, $at);
        });
    }

    /**
     * Marca "proceso CIP pendiente" del PLC. Mientras está en 1 el ciclo se sigue esperando;
     * si baja a 0 con el ciclo sin terminar, se corta: queda incompleto con lo que se cumplió.
     *
     * @return bool si cerró un ciclo
     */
    public function hold(Gateway $gateway, string $equipmentType, bool $on, CarbonImmutable $at): bool
    {
        $at = $at->utc()->startOfSecond();

        return DB::transaction(function () use ($gateway, $equipmentType, $on, $at) {
            $equipment = $this->equipmentFor($gateway->plant_id, $equipmentType);
            if (! $this->edge($equipment, self::HOLD, $on, $at) || $on) {
                return false;
            }

            $open = $this->openCycle($equipment);
            if (! $open) {
                return false;
            }
            $this->close($open, $at, self::REASON_HOLD_OFF);

            return true;
        });
    }

    /**
     * Todas las marcas de una misma muestra. Primero se procesan las que bajan: cuando los ciclos
     * van encadenados, el enjuague final baja en la misma muestra en la que sube el saneado siguiente.
     *
     * @param  list<array{equipment: string, stage: string, on: bool}>  $marks
     * @return int cuántas marcas cambiaron algún ciclo
     */
    public function signals(Gateway $gateway, array $marks, CarbonImmutable $at): int
    {
        usort($marks, fn ($a, $b) => $a['on'] <=> $b['on']);

        $changes = 0;
        foreach ($marks as $mark) {
            $changes += (int) $this->signal($gateway, $mark['equipment'], $mark['stage'], $mark['on'], $at);
        }

        return $changes;
    }

    /**
     * Cierra como incompletos los ciclos abiertos sin ninguna marca activa desde hace cip.stale_minutes.
     * Con 0 (por defecto) no se cierra nunca por tiempo: el ciclo espera la etapa que falta.
     */
    public function closeStale(CarbonImmutable $now): int
    {
        $minutes = (int) config('telemetry.cip.stale_minutes', 0);
        if ($minutes <= 0) {
            return 0;
        }
        $limit = $now->subMinutes($minutes);
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

            DB::transaction(fn () => $this->close($cycle, $last, self::REASON_STALE));
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
            ->whereIn('stage', CipCycle::STAGES)
            ->where('active', true)
            ->orderBy('changed_at')
            ->get()
            ->mapWithKeys(fn ($s) => [$s->equipment_id => ['stage' => $s->stage, 'since' => CarbonImmutable::parse($s->changed_at)]])
            ->all();
    }

    /** Guarda la muestra de una marca y dice si cambió de estado (flanco). */
    private function edge(Equipment $equipment, string $mark, bool $on, CarbonImmutable $at): bool
    {
        $key = ['equipment_id' => $equipment->id, 'stage' => $mark];
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

        return true;
    }

    private function stageStarted(Equipment $equipment, string $stage, CarbonImmutable $at): bool
    {
        $open = $this->openCycle($equipment);
        if ($open && $stage === CipCycle::STAGES[0]) {
            $this->close($open, $at, self::REASON_NEW_CYCLE);
            $open = null;
        }
        $open ??= CipCycle::create([
            'equipment_id' => $equipment->id,
            'plc_cycle_id' => 'AUTO-'.$at->format('YmdHis'),
            'started_at' => $at,
            'finished_at' => null,
            'total_min' => 0,
            'total_s' => 0,
            'result' => self::RUNNING,
        ]);

        $open->stages()->updateOrCreate(
            ['stage' => $stage],
            [
                'position' => array_search($stage, CipCycle::STAGES, true) + 1,
                'started_at' => $at,
                'ended_at' => null,
                'duration_min' => null,
                'duration_s' => null,
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
        if ($row) {
            $seconds = max(0, (int) round(CarbonImmutable::parse($row->started_at)->diffInSeconds($at)));
            $row->update([
                'ended_at' => $at,
                'duration_s' => $seconds,
                'duration_min' => (int) round($seconds / 60),
                'completed' => true,
            ]);
            $open->update($this->outcome($open->stages()->get()->toArray(), running: true));
        }

        if ($stage === CipCycle::STAGES[count(CipCycle::STAGES) - 1]) {
            $this->close($open, $at);
        }

        return true;
    }

    /** Completa las etapas que faltan como no cumplidas y deja el resultado final. */
    private function close(CipCycle $cycle, CarbonImmutable $at, ?string $reason = null): void
    {
        $existing = $cycle->stages()->get()->keyBy('stage');
        foreach (CipCycle::STAGES as $i => $name) {
            $row = $existing->get($name);
            if (! $row) {
                // Nunca arrancó (started_at null): "No inició".
                $cycle->stages()->create(['stage' => $name, 'position' => $i + 1, 'duration_min' => null, 'duration_s' => null, 'completed' => false]);
            } elseif (! $row->ended_at) {
                $row->update(['completed' => false]); // seguía corriendo cuando se cerró: "Cortada"
            }
        }

        $outcome = $this->outcome($cycle->stages()->get()->toArray());
        $cycle->update(['finished_at' => $at, 'close_reason' => $outcome['result'] === 'finalizado' ? null : $reason] + $outcome);
    }

    private function openCycle(Equipment $equipment): ?CipCycle
    {
        return CipCycle::where('equipment_id', $equipment->id)->where('result', self::RUNNING)->latest('started_at')->first();
    }

    /** @return array{total_min: int, total_s: int, result?: string} */
    private function outcome(array $stages, bool $running = false): array
    {
        $done = collect($stages)->where('completed', true);
        $seconds = (int) $done->sum(fn ($s) => $s['duration_s'] ?? ((int) ($s['duration_min'] ?? 0) * 60));

        return [
            'total_s' => $seconds,
            'total_min' => (int) round($seconds / 60),
        ] + ($running ? [] : [
            'result' => count($stages) === count(CipCycle::STAGES) && $done->count() === count($stages) ? 'finalizado' : 'incompleto',
        ]);
    }

    private function equipmentFor(int $plantId, string $type): Equipment
    {
        return Equipment::firstOrCreate(['plant_id' => $plantId, 'type' => $type], ['name' => ucfirst($type)]);
    }
}
