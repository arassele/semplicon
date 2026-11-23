<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Attribuzione di un pagamento (o di una nota di credito) a una rata. Consultabile da entrambi i lati. */
class Allocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allocation_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(MoneyMovement::class, 'movement_id');
    }

    public function creditInstallment(): BelongsTo
    {
        return $this->belongsTo(Installment::class, 'credit_installment_id');
    }

    /** Valida se né l'attribuzione né il movimento sono stornati. */
    public function isValid(): bool
    {
        return $this->status === 'valida' && (! $this->movement_id || $this->movement?->status === 'valida');
    }
}
