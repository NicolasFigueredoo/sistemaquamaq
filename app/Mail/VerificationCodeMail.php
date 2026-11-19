<?php

namespace App\Mail;

use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $purpose,
        public string $code,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Código de verificación · IVESS CONECTA 4.0');
    }

    public function content(): Content
    {
        $action = match ($this->purpose) {
            VerificationCode::EMAIL_CHANGE => 'confirmar este mail en tu perfil',
            VerificationCode::PASSWORD_CHANGE => 'cambiar tu contraseña',
            default => 'restablecer tu contraseña',
        };

        return new Content(view: 'mail.verification-code', with: ['action' => $action]);
    }
}
