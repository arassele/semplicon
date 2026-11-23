<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8', 'unit_price' => 'decimal:8', 'total' => 'decimal:8', 'rate' => 'decimal:2'];
    }
}
