<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Equipment;
use App\Models\Gateway;
use App\Models\Plant;
use App\Models\User;
use Database\Seeders\VariableSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function makePlant(string $name = 'Planta Test', string $serial = 'EM300-TEST'): array
    {
        $this->seed(VariableSeeder::class);
        $plant = Plant::create(['code' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'CABA']);
        $plant->settingsOrDefault();
        $gateway = Gateway::create(['plant_id' => $plant->id, 'serial' => $serial]);
        foreach (['lavadora', 'llenadora'] as $type) {
            Equipment::create(['plant_id' => $plant->id, 'type' => $type, 'name' => ucfirst($type)]);
        }

        return [$plant, $gateway];
    }

    protected function user(Role $role, array $plants = []): User
    {
        $user = User::create([
            'name' => ucfirst($role->value), 'username' => $role->value.uniqid(), 'password' => 'secreto123', 'role' => $role,
        ]);
        $user->plants()->sync(collect($plants)->pluck('id'));

        return $user;
    }

    /** Payload de telemetría con todas las variables dentro de parámetro, salvo las indicadas. */
    protected function payload(int $tsMs, array $override = []): string
    {
        return json_encode(['ts' => $tsMs, 'values' => array_merge([
            'ozone' => 0.58, 'temperature' => 63.0, 'alkaline' => 360, 'effective_time' => 121, 'chlorine' => 1,
        ], $override)]);
    }
}
