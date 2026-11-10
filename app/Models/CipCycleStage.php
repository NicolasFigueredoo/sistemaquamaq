<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CipCycleStage extends Model
{
    public $timestamps = false;

    protected $fillable = ['cip_cycle_id', 'stage', 'position', 'duration_min', 'duration_s', 'completed', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_min' => 'integer',
            'duration_s' => 'integer',
            'completed' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
