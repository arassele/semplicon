<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Attribuzione fiscale di una componente imputata a un anno d'imposta (calcolata da registrazione e pagamenti). */
class FiscalEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['event_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'fiscal_amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(VatRegistration::class, 'registration_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }
}
