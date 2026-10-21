<?php

namespace Database\Seeders;

use App\Models\Variable;
use Illuminate\Database\Seeder;

/** Catálogo de variables medidas. Necesario también en producción. */
class VariableSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['key' => 'ozone', 'name' => 'Ozono en llenadora', 'short_name' => 'Ozono en llenadora', 'alert_name' => 'Ozono en llenadora',
                'unit' => 'ppm', 'decimals' => 2, 'kind' => 'analog', 'rule' => 'min', 'default_setpoint' => 0.50,
                'setpoint_min' => 0, 'setpoint_max' => 100, 'chart_min' => 0.30, 'chart_max' => 0.80,
                'chart_color' => '#0b7fba', 'sort' => 1, 'chart_sort' => 1],
            ['key' => 'alkaline', 'name' => 'Producto alcalino', 'short_name' => 'Producto alcalino', 'alert_name' => 'Conductividad del producto alcalino',
                'unit' => 'mS/cm', 'decimals' => 1, 'kind' => 'analog', 'rule' => 'min', 'default_setpoint' => 20,
                'setpoint_min' => 0, 'setpoint_max' => 100, 'chart_min' => 10, 'chart_max' => 35,
                'chart_color' => '#7b62c7', 'sort' => 2, 'chart_sort' => 3],
            ['key' => 'temperature', 'name' => 'Temperatura de lavado', 'short_name' => 'Temperatura lavado', 'alert_name' => 'Temperatura de lavado',
                'unit' => '°C', 'decimals' => 1, 'kind' => 'analog', 'rule' => 'min', 'default_setpoint' => 60,
                'setpoint_min' => 0, 'setpoint_max' => 100, 'chart_min' => 0, 'chart_max' => 100,
                'chart_color' => '#18acc5', 'sort' => 3, 'chart_sort' => 2],
            ['key' => 'effective_time', 'name' => 'Tiempo efectivo', 'short_name' => 'Tiempo efectivo', 'alert_name' => 'Tiempo efectivo',
                'unit' => 's', 'decimals' => 0, 'kind' => 'analog', 'rule' => 'min', 'default_setpoint' => 120,
                'setpoint_min' => null, 'setpoint_max' => null, 'chart_min' => null, 'chart_max' => null,
                'chart_color' => null, 'sort' => 4, 'chart_sort' => null],
            ['key' => 'chlorine', 'name' => 'Cloro en enjuague', 'short_name' => 'Cloro en enjuague', 'alert_name' => 'Cloro en enjuague',
                'unit' => '', 'decimals' => 0, 'kind' => 'boolean', 'rule' => 'presence', 'default_setpoint' => 1,
                'setpoint_min' => null, 'setpoint_max' => null, 'chart_min' => null, 'chart_max' => null,
                'chart_color' => null, 'sort' => 5, 'chart_sort' => null],
        ];

        foreach ($rows as $row) {
            Variable::updateOrCreate(['key' => $row['key']], $row);
        }
    }
}
