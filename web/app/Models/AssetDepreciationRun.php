<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modulo Cespiti (asset_depreciation_runs). */
class AssetDepreciationRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['total_civil' => 'decimal:2', 'total_fiscal' => 'decimal:2', 'calculated_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'options' => 'array'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
