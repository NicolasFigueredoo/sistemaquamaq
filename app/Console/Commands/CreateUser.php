<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Plant;
use App\Models\User;
use Illuminate\Console\Command;

class CreateUser extends Command
{
    protected $signature = 'ivess:user {username} {--name=} {--role=asociado : admin | auditor | asociado} {--plant=* : código de planta (asociados)} {--password=}';

    protected $description = 'Crea o actualiza un usuario del panel.';

    public function handle(): int
    {
        $role = Role::tryFrom($this->option('role'));
        if (! $role) {
            $this->error('Rol inválido. Usá admin, auditor o asociado.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Contraseña');
        $user = User::updateOrCreate(
            ['username' => $this->argument('username')],
            ['name' => $this->option('name') ?: $this->argument('username'), 'role' => $role, 'password' => $password, 'active' => true],
        );

        if ($codes = $this->option('plant')) {
            $ids = Plant::whereIn('code', $codes)->pluck('id');
            $user->plants()->sync($ids);
        }

        $this->info("Usuario {$user->username} ({$role->value}) listo.");

        return self::SUCCESS;
    }
}
