<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /** Aziende che questo cliente gestisce da sé. */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'memberships')->withTimestamps()->orderBy('companies.name');
    }

    /** Accesso: azienda propria o amministratore. */
    public function canAccess(Company $company): bool
    {
        return $this->is_admin
            || Membership::query()->where('company_id', $company->id)->where('user_id', $this->id)->exists();
    }

    /** Per la scelta dell'azienda: l'amministratore vede tutte le aziende. */
    public function accessibleCompanies()
    {
        return $this->is_admin ? Company::query()->orderBy('name') : $this->companies();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return mb_strtoupper(collect($parts)->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join(''));
    }
}
