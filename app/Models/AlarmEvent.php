<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlarmEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['plant_id', 'variable_id', 'started_at', 'ended_at', 'duration_s', 'setpoint_at_start', 'worst_value'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_s' => 'integer',
            'setpoint_at_start' => 'float',
            'worst_value' => 'float',
        ];
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }
}
