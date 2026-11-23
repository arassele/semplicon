<?php

namespace App\Models;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'direction' => InvoiceDirection::class,
            'status' => InvoiceStatus::class,
            'doc_date' => 'date:Y-m-d',
            'receipt_date' => 'date:Y-m-d',
            'archived_at' => 'datetime',
            'issue_data' => 'array',
            'issued_at' => 'datetime',
            'transmitted_at' => 'datetime',
            'supplier_address' => 'array',
            'customer_address' => 'array',
            'exchange_rate' => 'decimal:6',
            'original_total' => 'decimal:2',
            'xb_relevant' => 'boolean',
            'xb_customs' => 'boolean',
            'xb_sdi' => 'boolean',
            'total_amount' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'issues' => 'array',
            'ritenuta' => 'decimal:2',
            'payment_check' => 'boolean',
            'amount_check' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function vatSummaries(): HasMany
    {
        return $this->hasMany(InvoiceVatSummary::class)->orderBy('id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(VatRegistration::class)->orderBy('id');
    }

    public function activeRegistration(): ?VatRegistration
    {
        return VatRegistration::where('active_invoice_id', $this->id)->first();
    }

    /** Controparte dal punto di vista dell'azienda. */
    public function counterpartyName(): string
    {
        return $this->direction === InvoiceDirection::Vendita ? $this->customer_name : $this->supplier_name;
    }

    /** Scadenziario: rate della fattura. */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('seq');
    }

    public function fiscalEntries(): HasMany
    {
        return $this->hasMany(FiscalEntry::class)->orderBy('event_date')->orderBy('id');
    }
}
