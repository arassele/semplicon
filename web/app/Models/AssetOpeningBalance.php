<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Modulo Cespiti (asset_opening_balances). */
class AssetOpeningBalance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fund_civil' => 'decimal:2', 'fund_fiscal' => 'decimal:2', 'increments' => 'decimal:2', 'decrements' => 'decimal:2', 'revaluations' => 'decimal:2'];
    }
}
