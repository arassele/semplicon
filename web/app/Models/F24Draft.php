<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bozza della delega F24 (Sezione Erario). Semplicon non trasmette e non dispone addebiti. */
class F24Draft extends Model
{
    protected $guarded = ['id'];

    public bool $replayed = false;

    protected function casts(): array
    {
        return ['lines' => 'array', 'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2', 'balance' => 'decimal:2', 'due_date' => 'date:Y-m-d',
            'presented_at' => 'date:Y-m-d', 'debit_confirmed_at' => 'date:Y-m-d'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(VatSettlement::class, 'settlement_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(VatCreditMovement::class, 'f24_id');
    }
}
