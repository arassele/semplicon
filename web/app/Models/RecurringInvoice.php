<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modello di una fattura ricorrente (vedi RecurringInvoiceService). */
class RecurringInvoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'active' => 'boolean', 'auto_issue' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lastInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'last_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
