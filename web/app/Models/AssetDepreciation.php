<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modulo Cespiti (asset_depreciation). */
class AssetDepreciation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['civil_rate' => 'decimal:2', 'fiscal_rate' => 'decimal:2', 'base_civil' => 'decimal:2', 'base_fiscal' => 'decimal:2', 'fund_civil_start' => 'decimal:2', 'civil_amount' => 'decimal:2', 'fund_civil_end' => 'decimal:2', 'fund_fiscal_start' => 'decimal:2', 'fiscal_limit' => 'decimal:2', 'fiscal_amount' => 'decimal:2', 'fund_fiscal_end' => 'decimal:2', 'nondeductible' => 'decimal:2', 'extra_deduction' => 'decimal:2'];
    }

    protected $table = 'asset_depreciation';

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
