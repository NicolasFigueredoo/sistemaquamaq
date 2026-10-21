<?php

namespace App\Enums;

enum PlantStatus: string
{
    case Conforme = 'conforme';
    case Atencion = 'atencion';
    case SinConexion = 'sin_conexion';
    case Alarma = 'alarma';

    /** Orden de la vista nacional: conformes → atención → sin conexión → alarmas. */
    public function rank(): int
    {
        return match ($this) {
            self::Conforme => 0,
            self::Atencion => 1,
            self::SinConexion => 2,
            self::Alarma => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Conforme => 'Conforme',
            self::Atencion => 'Atención',
            self::SinConexion => 'Sin conexión',
            self::Alarma => 'Alarma',
        };
    }

    public static function fromRank(int $rank): self
    {
        foreach (self::cases() as $case) {
            if ($case->rank() === $rank) {
                return $case;
            }
        }

        return self::Conforme;
    }
}
