<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modulo Cespiti (depreciation_tables). */
class DepreciationTable extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d'];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(DepreciationRate::class)->orderBy('position');
    }
}
