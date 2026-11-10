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
                'unit' => 'ppm', 'decimals' => 2, 'kind' => 'analog', 'rule' => 'range', 'default_setpoint' => 0.50, 'default_setpoint_high' => 1,
                'setpoint_min' => 0, 'setpoint_max' => 100, 'chart_min' => 0.30, 'chart_max' => 0.80,
                'chart_color' => '#0b7fba', 'sort' => 1, 'chart_sort' => 1],
            ['key' => 'alkaline', 'name' => 'Conductividad del agua', 'short_name' => 'Conductividad del agua', 'alert_name' => 'Conductividad del agua',
                'unit' => 'mS/cm', 'decimals' => 1, 'kind' => 'analog', 'rule' => 'min', 'default_setpoint' => 0.3,
                'setpoint_min' => 0, 'setpoint_max' => 20, 'chart_min' => 0, 'chart_max' => 20,
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
                'unit' => 'ppm', 'decimals' => 2, 'kind' => 'boolean', 'rule' => 'min', 'default_setpoint' => 0.1,
                'setpoint_min' => 0, 'setpoint_max' => 2, 'chart_min' => null, 'chart_max' => null,
                'chart_color' => null, 'sort' => 5, 'chart_sort' => null],
            // Mínimo por desinfección, máximo porque por arriba transfiere gusto: alarma por baja o por alta.
            ['key' => 'chlorine_dioxide', 'name' => 'Dióxido de cloro', 'short_name' => 'Dióxido de cloro', 'alert_name' => 'Dióxido de cloro',
                'unit' => 'ppm', 'decimals' => 2, 'kind' => 'analog', 'rule' => 'range', 'default_setpoint' => 0.15, 'default_setpoint_high' => 0.30,
                'setpoint_min' => 0, 'setpoint_max' => 100, 'chart_min' => 0, 'chart_max' => 0.5,
                'chart_color' => '#2f9e6e', 'sort' => 7, 'chart_sort' => 4],
            // Cuantitativo: alarma solo por baja. Sensor de 0 a 2000 ppm.
            ['key' => 'peracetic', 'name' => 'Ácido peracético', 'short_name' => 'Ácido peracético', 'alert_name' => 'Ácido peracético',
                'unit' => 'ppm', 'decimals' => 1, 'kind' => 'analog', 'rule' => 'min', 'default_setpoint' => 0.1,
                'setpoint_min' => 0, 'setpoint_max' => 2000, 'chart_min' => 0, 'chart_max' => 2000,
                'chart_color' => '#c2702a', 'sort' => 6, 'chart_sort' => 5],
        ];

        foreach ($rows as $row) {
            Variable::updateOrCreate(['key' => $row['key']], $row);
        }
    }
}
