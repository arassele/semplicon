<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Versione precedente conservata di una fattura prima di una rielaborazione. */
class InvoiceVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
