<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variable extends Model
{
    protected $fillable = [
        'key', 'name', 'short_name', 'alert_name', 'unit', 'decimals', 'kind', 'rule',
        'default_setpoint', 'default_setpoint_high', 'setpoint_min', 'setpoint_max', 'chart_min', 'chart_max',
        'chart_color', 'sort', 'chart_sort',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'default_setpoint' => 'float',
            'default_setpoint_high' => 'float',
            'setpoint_min' => 'float',
            'setpoint_max' => 'float',
            'chart_min' => 'float',
            'chart_max' => 'float',
            'sort' => 'integer',
            'chart_sort' => 'integer',
        ];
    }

    /** Cada planta usa uno solo de estos desinfectantes (PlantSetting::disinfectant). */
    public const DISINFECTANTS = ['ozone', 'chlorine_dioxide'];

    /** Key del canal compartido: la lectura se guarda como el desinfectante de la planta. */
    public const DISINFECTANT_CHANNEL = 'disinfectant';

    /** ¿El valor cumple el setpoint? En "range" el setpoint es el mínimo y $high el máximo. */
    public function complies(float $value, float $setpoint, ?float $high = null): bool
    {
        return match ($this->rule) {
            'max' => $value <= $setpoint,
            'range' => $value >= $setpoint && ($high === null || $value <= $high),
            default => $value >= $setpoint,
        };
    }

    /** Límite que el valor no cumple (el que se registra en la alarma). */
    public function breachedLimit(float $value, float $setpoint, ?float $high = null): float
    {
        return $this->rule === 'range' && $high !== null && $value > $high ? $high : $setpoint;
    }

    /** Equipo del que depende la variable: solo se registra mientras ese equipo produce. */
    public function equipmentType(): string
    {
        return in_array($this->key, self::DISINFECTANTS, true) ? 'llenadora' : 'lavadora';
    }

    /** Devuelve el peor de dos valores según la regla (el más alejado del cumplimiento). */
    public function worse(float $a, float $b, float $setpoint = 0, ?float $high = null): float
    {
        if ($this->rule === 'range') {
            $off = fn (float $v) => max($setpoint - $v, $v - ($high ?? INF), 0);

            return $off($b) > $off($a) ? $b : $a;
        }

        return $this->rule === 'max' ? max($a, $b) : min($a, $b);
    }

    /** Catálogo completo indexado por key. */
    public static function catalog(): \Illuminate\Support\Collection
    {
        return static::query()->orderBy('sort')->get()->keyBy('key');
    }
}
