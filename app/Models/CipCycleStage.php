<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CipCycleStage extends Model
{
    public $timestamps = false;

    protected $fillable = ['cip_cycle_id', 'stage', 'position', 'duration_min', 'completed'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_min' => 'integer',
            'completed' => 'boolean',
        ];
    }
}
