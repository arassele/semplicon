<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Modulo Cespiti (assets). */
class Asset extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date:Y-m-d', 'invoice_date' => 'date:Y-m-d', 'in_service_date' => 'date:Y-m-d', 'depreciation_start' => 'date:Y-m-d', 'original_cost' => 'decimal:2', 'accessory_costs' => 'decimal:2', 'nondeductible_vat' => 'decimal:2', 'grants' => 'decimal:2', 'residual_value' => 'decimal:2', 'fiscal_cost' => 'decimal:2', 'reduced_pct' => 'decimal:2', 'first_year_half' => 'boolean', 'excluded' => 'boolean', 'extra_deduction_pct' => 'decimal:2', 'fiscal_cost_cap' => 'decimal:2', 'fiscal_deductible_pct' => 'decimal:2', 'low_value' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(AssetRate::class)->orderBy('from_year');
    }

    public function opening(): HasOne
    {
        return $this->hasOne(AssetOpeningBalance::class);
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('year');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class)->orderBy('movement_date')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDocument::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** Conto: predefinito della categoria, sovrascrivibile per cespite. */
    public function account(string $role): ?int
    {
        return $this->{"account_{$role}_id"} ?? $this->category?->{"account_{$role}_id"};
    }
}
