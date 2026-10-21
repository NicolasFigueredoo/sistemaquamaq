<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantSetting extends Model
{
    protected $primaryKey = 'plant_id';

    public $incrementing = false;

    protected $fillable = ['plant_id', 'cip_period_days', 'cip_warning_hours', 'tank_volume_l', 'product_liters', 'offline_after_minutes', 'updated_by'];

    protected $attributes = [
        'cip_period_days' => 7,
        'cip_warning_hours' => 24,
        'tank_volume_l' => 1000,
        'product_liters' => 18.2,
        'offline_after_minutes' => 10,
    ];

    protected function casts(): array
    {
        return [
            'cip_period_days' => 'integer',
            'cip_warning_hours' => 'integer',
            'tank_volume_l' => 'float',
            'product_liters' => 'float',
            'offline_after_minutes' => 'integer',
        ];
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }
}
