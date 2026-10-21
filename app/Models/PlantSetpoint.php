<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantSetpoint extends Model
{
    use HasCompositeKey;

    protected array $compositeKey = ['plant_id', 'variable_id'];

    protected $primaryKey = 'plant_id';

    protected $fillable = ['plant_id', 'variable_id', 'setpoint', 'alarm_delay_s', 'updated_by'];

    protected function casts(): array
    {
        return [
            'setpoint' => 'float',
            'alarm_delay_s' => 'integer',
        ];
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }
}
