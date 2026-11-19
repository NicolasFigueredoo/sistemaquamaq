<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationCode extends Model
{
    public const EMAIL_CHANGE = 'email_change';
    public const PASSWORD_CHANGE = 'password_change';
    public const PASSWORD_RESET = 'password_reset';

    protected $fillable = ['user_id', 'purpose', 'email', 'code_hash', 'attempts', 'expires_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
