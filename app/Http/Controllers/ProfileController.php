<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\PasswordUpdater;
use App\Models\VerificationCode;
use App\Services\Auth\VerificationCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Mi perfil: mail de contacto y cambio de contraseña, ambos confirmados con un código por mail. */
class ProfileController extends Controller
{
    public function __construct(private VerificationCodes $codes) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile', [
            'profile' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'emailVerified' => $user->email_verified_at !== null,
            ],
            'pendingEmail' => $this->codes->pending($user, VerificationCode::EMAIL_CHANGE)?->email,
            'passwordCodeSent' => $this->codes->pending($user, VerificationCode::PASSWORD_CHANGE) !== null,
            'support' => config('app.support'),
        ]);
    }

    public function sendEmailCode(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], ['email.unique' => 'Ese mail ya está registrado en otro usuario.']);

        if ($data['email'] === Str::lower((string) $user->email) && $user->email_verified_at) {
            throw ValidationException::withMessages(['email' => 'Ese ya es tu mail registrado.']);
        }

        $this->codes->send($user, VerificationCode::EMAIL_CHANGE, $data['email'], 'email');

        return back()->with('status', "Te enviamos un código a {$data['email']}.");
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['email_code' => ['required', 'string', 'max:10']]);

        $record = $this->codes->consume($user, VerificationCode::EMAIL_CHANGE, $data['email_code'], 'email_code');

        if ($user->newQuery()->where('email', $record->email)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email_code' => 'Ese mail ya está registrado en otro usuario.']);
        }

        $user->forceFill(['email' => $record->email, 'email_verified_at' => now()])->save();

        return back()->with('status', 'Mail confirmado y guardado.');
    }

    public function sendPasswordCode(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->email) {
            throw ValidationException::withMessages(['password_code' => 'Primero registrá un mail en tu perfil.']);
        }

        $this->codes->send($user, VerificationCode::PASSWORD_CHANGE, $user->email, 'password_code');

        return back()->with('status', "Te enviamos un código a {$user->email}.");
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'password_code' => ['required', 'string', 'max:10'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $this->codes->consume($user, VerificationCode::PASSWORD_CHANGE, $data['password_code'], 'password_code');

        $request->session()->regenerate();
        PasswordUpdater::apply($user, $data['password'], $request->session()->getId());

        return back()->with('status', 'Contraseña cambiada. Se cerraron las otras sesiones abiertas.');
    }
}
