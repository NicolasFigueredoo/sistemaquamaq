<?php

namespace App\Enums;

enum Role: string
{
    /** Ve todas las plantas, configura setpoints y escribe notas. */
    case Admin = 'admin';
    /** Ve todas las plantas y escribe notas de auditoría. */
    case Auditor = 'auditor';
    /** Ve solo sus plantas, en modo lectura. */
    case Asociado = 'asociado';
}
