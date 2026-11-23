<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Movimento di denaro (incasso/pagamento), indipendente dalle rate; attribuzione tramite Allocation. */
class MoneyMovement extends Model
{
    protected $guarded = ['id'];

    public bool $replayed = false;

    protected function casts(): array
    {
        return ['movement_date' => 'date:Y-m-d', 'payer_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'payer_year_rule' => 'boolean', 'reversed_at' => 'datetime'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'movement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
