<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rielaborazione di un XML: proposta con differenze, in attesa di conferma (sezione 2). */
class InvoiceReprocessing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'diff' => 'array', 'impacts' => 'array', 'candidates' => 'array', 'booking_relevant' => 'boolean', 'applied_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function importItem(): BelongsTo
    {
        return $this->belongsTo(ImportItem::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
