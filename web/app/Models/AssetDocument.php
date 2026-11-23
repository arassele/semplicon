<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modulo Cespiti (asset_documents). */
class AssetDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
