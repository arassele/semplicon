<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Versione confermata di una liquidazione IVA (ticket 11). I contenuti non vengono modificati; correzione = nuova versione. */
class VatSettlement extends Model
{
    protected $guarded = ['id'];

    public bool $replayed = false;

    protected function casts(): array
    {
        return ['data' => 'array', 'review_required' => 'boolean', 'amount_due' => 'decimal:2', 'credit' => 'decimal:2', 'deferred' => 'decimal:2',
            'due_date' => 'date:Y-m-d', 'confirmed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(VatProfile::class, 'vat_profile_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function f24s(): HasMany
    {
        return $this->hasMany(F24Draft::class, 'settlement_id');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(VatCredit::class, 'settlement_id');
    }
}
