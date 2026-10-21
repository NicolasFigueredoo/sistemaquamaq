<?php

namespace App\Services\Telemetry;

use App\Enums\CipStatus;
use App\Enums\PlantStatus;
use App\Enums\VariableState;
use App\Models\Plant;
use Carbon\CarbonImmutable;

/**
 * Calcula el estado de la planta que se ve en la red nacional. Prioridad:
 *   - Sin gateway online → "sin conexión" (los valores no son confiables).
 *   - Alguna variable en alarma, o CIP vencido → "alarma".
 *   - Alguna variable en atención, o CIP próximo a vencer → "atención".
 *   - Si no, "conforme".
 * Un CIP vencido gana incluso a "sin conexión", porque no depende del enlace.
 */
class PlantStatusService
{
    public function __construct(private CipService $cip) {}

    public function compute(Plant $plant, CarbonImmutable $now): PlantStatus
    {
        $plant->loadMissing(['gateways', 'variableStates', 'equipment.lastCompleteCycle']);
        $settings = $plant->settingsOrDefault();

        $online = $plant->gateways->contains(fn ($g) => $g->online);

        $variableRank = 0;
        foreach ($plant->variableStates as $state) {
            $variableRank = max($variableRank, match ($state->state) {
                VariableState::Alarma => PlantStatus::Alarma->rank(),
                VariableState::Atencion => PlantStatus::Atencion->rank(),
                default => 0,
            });
        }

        $cipRank = 0;
        $expired = PlantStatus::from(config('telemetry.cip.expired_plant_status', 'alarma'));
        foreach ($plant->equipment as $equipment) {
            $status = $this->cip->statusFor($equipment, $settings, $now)['status'];
            $cipRank = max($cipRank, match ($status) {
                CipStatus::Vencido => $expired->rank(),
                CipStatus::Proximo => PlantStatus::Atencion->rank(),
                CipStatus::SinRegistro => config('telemetry.cip.missing_is_attention') ? PlantStatus::Atencion->rank() : 0,
                default => 0,
            });
        }

        if (! $online) {
            return $cipRank === PlantStatus::Alarma->rank() ? PlantStatus::Alarma : PlantStatus::SinConexion;
        }

        return PlantStatus::fromRank(max($variableRank, $cipRank));
    }

    public function refresh(Plant $plant, ?CarbonImmutable $now = null): PlantStatus
    {
        $now ??= CarbonImmutable::now();
        $plant->unsetRelation('gateways')->unsetRelation('variableStates')->unsetRelation('equipment');

        $status = $this->compute($plant, $now);

        if ($plant->status !== $status) {
            $plant->status = $status;
            $plant->status_changed_at = $now;
            $plant->save();
        }

        return $status;
    }
}
