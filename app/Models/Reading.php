<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reading extends Model
{
    public $timestamps = false;

    protected $fillable = ['plant_id', 'variable_id', 'gateway_id', 'value', 'measured_at', 'received_at'];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'measured_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
