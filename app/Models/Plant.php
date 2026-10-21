<?php

namespace App\Models;

use App\Enums\PlantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Plant extends Model
{
    protected $fillable = ['code', 'name', 'location', 'province', 'status', 'status_changed_at', 'last_reading_at', 'active'];

    protected function casts(): array
    {
        return [
            'status' => PlantStatus::class,
            'status_changed_at' => 'datetime',
            'last_reading_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(PlantSetting::class);
    }

    public function gateways(): HasMany
    {
        return $this->hasMany(Gateway::class);
    }

    public function setpoints(): HasMany
    {
        return $this->hasMany(PlantSetpoint::class);
    }

    public function variableStates(): HasMany
    {
        return $this->hasMany(PlantVariableState::class);
    }

    public function alarmEvents(): HasMany
    {
        return $this->hasMany(AlarmEvent::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function auditorNotes(): HasMany
    {
        return $this->hasMany(AuditorNote::class);
    }

    /** Devuelve la configuración, creándola con valores por defecto si no existe. */
    public function settingsOrDefault(): PlantSetting
    {
        return $this->settings ?? $this->settings()->create();
    }
}
