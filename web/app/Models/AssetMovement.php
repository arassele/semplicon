<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modulo Cespiti (asset_movements). */
class AssetMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['movement_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'fiscal_amount' => 'decimal:2', 'price' => 'decimal:2', 'vat' => 'decimal:2', 'sale_costs' => 'decimal:2', 'book_value' => 'decimal:2', 'fiscal_value' => 'decimal:2', 'gain_civil' => 'decimal:2', 'gain_fiscal' => 'decimal:2', 'fund_civil' => 'decimal:2', 'fund_fiscal' => 'decimal:2', 'cost_civil' => 'decimal:2', 'cost_fiscal' => 'decimal:2', 'share_pct' => 'decimal:2', 'fiscal_recognized' => 'boolean', 'data' => 'array'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
