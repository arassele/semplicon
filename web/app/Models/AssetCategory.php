<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modulo Cespiti (asset_categories). */
class AssetCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['civil_rate' => 'decimal:2', 'fiscal_rate' => 'decimal:2', 'first_year_half' => 'boolean', 'active' => 'boolean'];
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(DepreciationRate::class, 'depreciation_rate_id');
    }

    public function rateChanges(): HasMany
    {
        return $this->hasMany(AssetCategoryRate::class)->orderBy('from_year');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
