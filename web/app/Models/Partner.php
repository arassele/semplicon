<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anagrafica di un cliente e/o fornitore di un'azienda. */
class Partner extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_customer' => 'boolean', 'is_supplier' => 'boolean', 'is_pa' => 'boolean', 'active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function displayName(): string
    {
        return $this->kind === 'persona' ? trim("{$this->first_name} {$this->last_name}") : (string) $this->name;
    }
}
