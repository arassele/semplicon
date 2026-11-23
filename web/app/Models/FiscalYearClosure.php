<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Modulo Cespiti (fiscal_year_closures). */
class FiscalYearClosure extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }
}
