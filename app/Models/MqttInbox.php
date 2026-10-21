<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MqttInbox extends Model
{
    protected $table = 'mqtt_inbox';

    public $timestamps = false;

    protected $fillable = ['gateway_id', 'topic', 'payload', 'received_at', 'processed_at', 'error'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
