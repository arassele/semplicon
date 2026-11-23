<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Destinazione di una quota di credito: riporto, compensazione F24, rimborso o passaggio a un credito TR. */
class VatCreditMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(VatCredit::class, 'credit_id');
    }

    public function f24(): BelongsTo
    {
        return $this->belongsTo(F24Draft::class, 'f24_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(VatClaim::class, 'claim_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(VatSettlement::class, 'settlement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
