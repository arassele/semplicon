<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Modulo Cespiti (asset_rates). */
class AssetRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['civil_rate' => 'decimal:2', 'fiscal_rate' => 'decimal:2', 'manual_civil' => 'decimal:2', 'manual_fiscal' => 'decimal:2'];
    }
}
