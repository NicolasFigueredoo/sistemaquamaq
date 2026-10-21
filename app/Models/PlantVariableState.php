<?php

namespace App\Models;

use App\Enums\VariableState;
use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantVariableState extends Model
{
    use HasCompositeKey;

    protected array $compositeKey = ['plant_id', 'variable_id'];

    protected $primaryKey = 'plant_id';

    public $timestamps = false;

    protected $fillable = ['plant_id', 'variable_id', 'state', 'last_value', 'last_measured_at', 'out_since', 'worst_value', 'open_alarm_event_id'];

    protected function casts(): array
    {
        return [
            'state' => VariableState::class,
            'last_value' => 'float',
            'last_measured_at' => 'datetime',
            'out_since' => 'datetime',
            'worst_value' => 'float',
        ];
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }
}
