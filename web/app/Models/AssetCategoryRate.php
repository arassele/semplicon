<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Modulo Cespiti (asset_category_rates). */
class AssetCategoryRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['civil_rate' => 'decimal:2', 'fiscal_rate' => 'decimal:2'];
    }
}
