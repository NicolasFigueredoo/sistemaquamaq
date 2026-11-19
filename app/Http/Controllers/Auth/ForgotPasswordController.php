<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\Auth\VerificationCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Olvidé mi contraseña": se manda un código al mail registrado del usuario.
 * La respuesta es la misma exista o no el usuario, para no revelar quién está dado de alta.
 */
class ForgotPasswordController extends Controller
{
    public function __construct(private VerificationCodes $codes) {}

    public function show(Request $request): Response
    {
        return Inertia::render('ForgotPassword', [
            'step' => $request->session()->has('reset.identifier') ? 'code' : 'request',
            'identifier' => $request->session()->get('reset.identifier'),
            'support' => config('app.support'),
        ]);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']]);
        $identifier = trim($data['identifier']);

        $key = 'forgot:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'identifier' => 'Demasiados pedidos. Probá de nuevo en '.RateLimiter::availableIn($key).' segundos.',
            ]);
        }
        RateLimiter::hit($key, 300);

        $user = User::findForLogin($identifier);
        if ($user && $user->email) {
            $this->codes->send($user, VerificationCode::PASSWORD_RESET, $user->email, 'identifier');
        }

        $request->session()->put('reset', ['identifier' => $identifier, 'user_id' => $user?->email ? $user->id : null]);

        return redirect()->route('password.forgot')->with('status',
            'Si los datos corresponden a un usuario con mail registrado, te enviamos un código. Revisá tu casilla (y la carpeta de spam).');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $userId = $request->session()->get('reset.user_id');
        $user = $userId ? User::where('active', true)->find($userId) : null;

        $this->codes->consume($user, VerificationCode::PASSWORD_RESET, $data['code']);

        PasswordUpdater::apply($user, $data['password']);
        $request->session()->forget('reset');

        return redirect()->route('login')->with('status', 'Listo, tu contraseña fue cambiada. Ya podés ingresar.');
    }

    public function restart(Request $request): RedirectResponse
    {
        $request->session()->forget('reset');

        return redirect()->route('password.forgot');
    }
}
