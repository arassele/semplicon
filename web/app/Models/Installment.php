<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rata / partita aperta di una fattura (Scadenziario). */
class Installment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'edited' => 'boolean'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    /** Attribuzioni in cui questa rata è stata usata come nota di credito a compensazione. */
    public function creditAllocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'credit_installment_id');
    }
}
