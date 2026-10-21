<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CipCycle extends Model
{
    public const STAGES = ['saneado', 'enjuague', 'desinfeccion', 'enjuague_final'];

    public const STAGE_LABELS = [
        'saneado' => 'Saneado',
        'enjuague' => 'Enjuague',
        'desinfeccion' => 'Desinfección',
        'enjuague_final' => 'Enjuague final',
    ];

    protected $fillable = ['equipment_id', 'plc_cycle_id', 'started_at', 'finished_at', 'total_min', 'result'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'total_min' => 'integer',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(CipCycleStage::class)->orderBy('position');
    }
}
