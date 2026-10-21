<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gateway extends Model
{
    protected $fillable = ['plant_id', 'serial', 'model', 'firmware', 'ip', 'mqtt_username', 'api_token_hash', 'last_seen_at', 'online'];

    protected $hidden = ['api_token_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'online' => 'boolean',
        ];
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    public function connectionEvents(): HasMany
    {
        return $this->hasMany(ConnectionEvent::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
