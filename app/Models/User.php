<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'username', 'email', 'password', 'role', 'active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
        ];
    }

    public function plants(): BelongsToMany
    {
        return $this->belongsToMany(Plant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** Admin y auditor ven toda la red; el asociado solo sus plantas. */
    public function seesAllPlants(): bool
    {
        return in_array($this->role, [Role::Admin, Role::Auditor], true);
    }

    public function isScreen(): bool
    {
        return $this->role === Role::Pantalla;
    }

    public function canWriteNotes(): bool
    {
        return in_array($this->role, [Role::Admin, Role::Auditor], true);
    }

    public function canSeePlant(Plant $plant): bool
    {
        return ! $this->isScreen() && ($this->seesAllPlants() || $this->plants()->whereKey($plant->id)->exists());
    }

    /** Plantas visibles para este usuario. */
    public function visiblePlants()
    {
        return $this->seesAllPlants() || $this->isScreen()
            ? Plant::query()->where('active', true)
            : $this->plants()->where('active', true)->getQuery();
    }
}
