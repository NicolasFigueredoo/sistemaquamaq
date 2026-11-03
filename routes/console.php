<?php

use Illuminate\Support\Facades\Schedule;

// Detecta gateways caídos, vencimientos de CIP y recalcula el estado de cada planta.
Schedule::command('telemetry:evaluate')->everyMinute()->withoutOverlapping();

// Trae los datos de la unidad Exemys M2M (planta de prueba). Sin EXEMYS_UNIT_ID no hace nada.
// Con EXEMYS_SIMULATE=true se simula la planta en su lugar (variables + marcas CIP del PLC).
Schedule::command('exemys:sync')->everyMinute()->withoutOverlapping()
    ->when(fn () => config('telemetry.exemys.unit_id') > 0 && ! config('telemetry.exemys.simulate'));
Schedule::command('telemetry:simulate --exemys')->everyMinute()->withoutOverlapping()
    ->when(fn () => config('telemetry.exemys.simulate'));

// Borra lecturas crudas y mensajes MQTT viejos (los promedios horarios se conservan).
Schedule::command('telemetry:prune')->dailyAt('03:15');
