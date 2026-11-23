<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceVatSummary extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'taxable' => 'decimal:2', 'tax' => 'decimal:2'];
    }
}
