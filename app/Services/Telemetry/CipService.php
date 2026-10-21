<?php

namespace App\Services\Telemetry;

use App\Enums\CipStatus;
use App\Models\CipCycle;
use App\Models\Equipment;
use App\Models\Gateway;
use App\Models\PlantSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CipService
{
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
            $equipment = Equipment::firstOrCreate(
                ['plant_id' => $gateway->plant_id, 'type' => $data['equipment']],
                ['name' => ucfirst($data['equipment'])],
            );

            $existing = CipCycle::where('equipment_id', $equipment->id)->where('plc_cycle_id', $data['cycle_id'])->first();
            if ($existing) {
                return $existing;
            }

            $complete = collect($data['stages'])->every(fn ($s) => $s['completed']);
            $total = (int) collect($data['stages'])->where('completed', true)->sum('duration_min');

            $cycle = CipCycle::create([
                'equipment_id' => $equipment->id,
                'plc_cycle_id' => $data['cycle_id'],
                'started_at' => $data['started_at'],
                'finished_at' => $data['finished_at'],
                'total_min' => $total,
                'result' => $complete ? 'finalizado' : 'incompleto',
            ]);

            foreach ($data['stages'] as $stage) {
                $cycle->stages()->create($stage);
            }

            return $cycle;
        });
    }
}
