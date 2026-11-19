<?php

namespace App\Services\Auth;

use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Genera, envía y verifica códigos de 6 dígitos por mail.
 * Cada usuario tiene a lo sumo un código vigente por propósito.
 */
class VerificationCodes
{
    public const TTL_MINUTES = 15;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 60;

    /** Manda un código nuevo (invalida el anterior). $field es el campo del formulario donde mostrar errores. */
    public function send(User $user, string $purpose, string $email, string $field = 'code'): void
    {
        $last = $this->current($user, $purpose);
        if ($last && $last->created_at->diffInSeconds(now()) < self::RESEND_SECONDS) {
            $wait = self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now());
            throw ValidationException::withMessages([$field => "Esperá {$wait} segundos antes de pedir otro código."]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        VerificationCode::where('user_id', $user->id)->where('purpose', $purpose)->delete();
        VerificationCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        Mail::to($email)->send(new VerificationCodeMail($user, $purpose, $code, self::TTL_MINUTES));
    }

    /** Valida el código y lo consume. Devuelve el registro (con el mail al que se envió) o lanza error de validación. */
    public function consume(?User $user, string $purpose, string $code, string $field = 'code'): VerificationCode
    {
        $record = $user ? $this->current($user, $purpose) : null;
        $fail = fn (string $msg) => throw ValidationException::withMessages([$field => $msg]);

        if (! $record || $record->expires_at->isPast()) {
            $record?->delete();
            $fail('El código venció o no existe. Pedí uno nuevo.');
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            $record->delete();
            $fail('Demasiados intentos con este código. Pedí uno nuevo.');
        }

        if (! Hash::check(trim($code), $record->code_hash)) {
            $record->increment('attempts');
            $fail('Código incorrecto.');
        }

        $record->delete();

        return $record;
    }

    public function pending(User $user, string $purpose): ?VerificationCode
    {
        $record = $this->current($user, $purpose);

        return $record && $record->expires_at->isFuture() ? $record : null;
    }

    private function current(User $user, string $purpose): ?VerificationCode
    {
        return VerificationCode::where('user_id', $user->id)->where('purpose', $purpose)->latest('id')->first();
    }
}
