<?php

namespace App\Enums;

enum VariableState: string
{
    case Ok = 'ok';
    /** Fuera de parámetro, todavía dentro del tiempo de tolerancia. */
    case Atencion = 'atencion';
    /** Fuera de parámetro más allá del tiempo de tolerancia: queda registrada como evento. */
    case Alarma = 'alarma';
}
