<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conto del piano dei conti (costo o ricavo) con impostazioni fiscali indicative. */
class Account extends Model
{
    public const FISCAL = ['sezione', 'voce_cee', 'categoria', 'iva_rilevante', 'iva_detraibile_pct', 'iva_nota', 'deducibilita_pct', 'deducibilita_nota', 'irap_nota', 'limite', 'normativa', 'note'];

    protected $fillable = ['company_id', 'code', 'name', 'type', 'active', 'is_default', 'source', ...self::FISCAL];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_default' => 'boolean', 'iva_rilevante' => 'boolean', 'iva_detraibile_pct' => 'decimal:2', 'deducibilita_pct' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isUsed(): bool
    {
        return VatRegistrationLine::where('account_id', $this->id)->exists() || NonVatEntry::where('account_id', $this->id)->exists();
    }
}
