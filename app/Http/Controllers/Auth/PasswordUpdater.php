<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Guarda la contraseña nueva y cierra las demás sesiones abiertas del usuario. */
final class PasswordUpdater
{
    public static function apply(User $user, string $password, ?string $keepSessionId = null): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        }
    }
}
