<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Instalador para hostings sin SSH (Hostinger compartido):
 *   https://tu-subdominio/instalar?token=EL_INSTALL_TOKEN_DEL_.env           → crea las tablas
 *   https://tu-subdominio/instalar?token=EL_INSTALL_TOKEN_DEL_.env&demo=1    → tablas + demo de 20 plantas
 * Solo funciona si INSTALL_TOKEN tiene valor. Después de instalar, borrá ese valor del .env.
 */
class InstallController extends Controller
{
    public function __invoke(Request $request)
    {
        $token = (string) config('telemetry.install_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->query('token')), 404);

        @set_time_limit(300);
        $out = [];

        Artisan::call('migrate', ['--force' => true]);
        $out[] = trim(Artisan::output());

        $alreadyInstalled = Schema::hasTable('users') && User::query()->exists();

        if ($request->boolean('demo')) {
            if ($alreadyInstalled && ! $request->boolean('force')) {
                $out[] = 'Ya hay usuarios cargados: no se volvió a cargar la demo (agregá &force=1 para pisarla).';
            } else {
                Artisan::call('db:seed', ['--force' => true]);
                $out[] = trim(Artisan::output());

                // En un servidor público las contraseñas de la demo no pueden ser las del README.
                if (! app()->environment('local')) {
                    $out[] = '';
                    $out[] = 'Contraseñas generadas (anotalas, no se vuelven a mostrar):';
                    foreach (User::query()->orderBy('id')->get() as $user) {
                        $password = Str::password(12, symbols: false);
                        $user->update(['password' => $password]);
                        $out[] = sprintf('  %-10s %-10s %s', $user->username, $user->role->value, $password);
                    }
                }
            }
        } else {
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\VariableSeeder', '--force' => true]);
            $out[] = 'Catálogo de variables cargado.';
            if (! User::query()->exists()) {
                $out[] = 'Todavía no hay usuarios: creá el admin con ?token=...&demo=1 o por SSH con php artisan ivess:user.';
            }
        }

        $out[] = '';
        $out[] = 'Listo. Ahora borrá el valor de INSTALL_TOKEN en el .env.';

        return response(implode("\n", $out), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
