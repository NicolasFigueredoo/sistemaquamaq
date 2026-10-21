<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SetpointChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['plant_id', 'field', 'old_value', 'new_value', 'user_id', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime', 'old_value' => 'float', 'new_value' => 'float'];
    }
}
