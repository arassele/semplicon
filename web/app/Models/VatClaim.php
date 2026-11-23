<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pratica di rimborso: IVA TR (trimestrale) o dati per la dichiarazione annuale. */
class VatClaim extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['refund_amount' => 'decimal:2', 'compensation_amount' => 'decimal:2', 'deadline' => 'date:Y-m-d', 'filed_at' => 'date:Y-m-d', 'outcome_at' => 'date:Y-m-d'];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(VatCredit::class, 'credit_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(VatCreditMovement::class, 'claim_id');
    }
}
