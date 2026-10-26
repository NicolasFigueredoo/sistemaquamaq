<?php

namespace App\Support;

/**
 * Señales sintéticas para la demo y el simulador (mismas curvas que la maqueta HTML).
 * $t es tiempo en horas (puede tener decimales); $phase desfasa cada planta.
 */
class DemoSignals
{
    public static function ozone(float $t, int $phase): float
    {
        $i = $t + $phase;

        return 0.57 + sin($i / 11) * 0.035 + sin($i / 47) * 0.02;
    }

    public static function temperature(float $t, int $phase): float
    {
        $i = $t + $phase;

        return 63.8 + sin($i / 13) * 2.2 + sin($i / 70) * 1.2;
    }

    public static function alkaline(float $t, int $phase): float
    {
        $i = $t + $phase;

        return 369 + sin($i / 15) * 31.5 + sin($i / 62) * 21;
    }

    /** Caídas periódicas (una hora cada tantas) para que haya cruces del setpoint. */
    public static function dip(string $key, float $t, int $phase): float
    {
        $i = (int) floor($t) + $phase;

        return match ($key) {
            'ozone' => ($i % 193) < 1 ? 0.11 : 0,
            'temperature' => ($i % 217) < 1 ? 8 : 0,
            'alkaline' => ($i % 181) < 1 ? 97.5 : 0,
            default => 0,
        };
    }

    public static function value(string $key, float $t, int $phase, bool $withDips = true): float
    {
        $base = match ($key) {
            'ozone' => self::ozone($t, $phase),
            'temperature' => self::temperature($t, $phase),
            'alkaline' => self::alkaline($t, $phase),
            'effective_time' => 122 + round(sin(($t + $phase) / 5) * 1.4),
            'chlorine' => 1,
            default => 0,
        };

        return $withDips ? $base - self::dip($key, $t, $phase) : $base;
    }
}
