<?php

namespace App\Models;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Registro crediti IVA: un credito con origine e movimenti; residuo = maturato − movimenti vincolanti. */
class VatCredit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'visto' => 'boolean', 'visto_exempt_limit' => 'decimal:2', 'filed_at' => 'date:Y-m-d'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(VatCreditMovement::class, 'credit_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(VatSettlement::class, 'settlement_id');
    }

    public function sourceCredit(): BelongsTo
    {
        return $this->belongsTo(VatCredit::class, 'source_credit_id');
    }

    /** Residuo in centesimi: prenotata e utilizzata vincolano, sbloccata libera. */
    public function residualCents(): int
    {
        return Decimal::toMinor((string) $this->amount) - $this->movements()->whereIn('status', ['prenotata', 'utilizzata'])->get()->sum(fn ($m) => Decimal::toMinor((string) $m->amount));
    }
}
