<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Integrazione IVA / autofattura (TD16–TD19) relativa a una fattura di acquisto (sezione 5). */
class InvoiceIntegration extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['groups' => 'array', 'doc_date' => 'date:Y-m-d'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
