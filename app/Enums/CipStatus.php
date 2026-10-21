<?php

namespace App\Enums;

enum CipStatus: string
{
    case Vigente = 'vigente';
    case Proximo = 'proximo';
    case Vencido = 'vencido';
    case SinRegistro = 'sin_registro';

    public function label(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Proximo => 'Próximo a vencer',
            self::Vencido => 'Vencido',
            self::SinRegistro => 'Sin registro',
        };
    }
}
