<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VatRegistrationLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'taxable' => 'decimal:2', 'tax' => 'decimal:2', 'deductible_pct' => 'decimal:2', 'deductible_tax' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
