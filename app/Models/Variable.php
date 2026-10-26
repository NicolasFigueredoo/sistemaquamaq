<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variable extends Model
{
    protected $fillable = [
        'key', 'name', 'short_name', 'alert_name', 'unit', 'decimals', 'kind', 'rule',
        'default_setpoint', 'setpoint_min', 'setpoint_max', 'chart_min', 'chart_max',
        'chart_color', 'sort', 'chart_sort',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'default_setpoint' => 'float',
            'setpoint_min' => 'float',
            'setpoint_max' => 'float',
            'chart_min' => 'float',
            'chart_max' => 'float',
            'sort' => 'integer',
            'chart_sort' => 'integer',
        ];
    }

    /** ¿El valor cumple el setpoint? */
    public function complies(float $value, float $setpoint): bool
    {
        return match ($this->rule) {
            'max' => $value <= $setpoint,
            'presence' => $value >= 0.5,
            default => $value >= $setpoint,
        };
    }

    /** Variables sin estado de atención: apenas salen de parámetro pasan a alarma. */
    public const IMMEDIATE_ALARM = ['temperature'];

    /** Segundos fuera de parámetro antes de ser alarma, según la variable y el setpoint configurado. */
    public function alarmDelay(int $configured): int
    {
        return in_array($this->key, self::IMMEDIATE_ALARM, true) ? 0 : $configured;
    }

    /** Devuelve el peor de dos valores según la regla (el más alejado del cumplimiento). */
    public function worse(float $a, float $b): float
    {
        return $this->rule === 'max' ? max($a, $b) : min($a, $b);
    }

    /** Catálogo completo indexado por key. */
    public static function catalog(): \Illuminate\Support\Collection
    {
        return static::query()->orderBy('sort')->get()->keyBy('key');
    }
}
