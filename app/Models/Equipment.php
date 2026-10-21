<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Equipment extends Model
{
    protected $table = 'equipment';

    protected $fillable = ['plant_id', 'type', 'name'];

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    public function cipCycles(): HasMany
    {
        return $this->hasMany(CipCycle::class);
    }

    /** Último ciclo CIP finalizado (los incompletos no cuentan para la periodicidad). */
    public function lastCompleteCycle(): HasOne
    {
        return $this->hasOne(CipCycle::class)->ofMany(['finished_at' => 'max'], fn ($q) => $q->where('result', 'finalizado'));
    }
}
