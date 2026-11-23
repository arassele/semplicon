<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modulo Cespiti (depreciation_rates). */
class DepreciationRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['coefficient' => 'decimal:2'];
    }

    public $timestamps = false;

    public function table(): BelongsTo
    {
        return $this->belongsTo(DepreciationTable::class, 'depreciation_table_id');
    }
}
