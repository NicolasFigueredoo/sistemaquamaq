<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['gateway_id', 'offline_at', 'online_at', 'duration_s'];

    protected function casts(): array
    {
        return [
            'offline_at' => 'datetime',
            'online_at' => 'datetime',
            'duration_s' => 'integer',
        ];
    }
}
