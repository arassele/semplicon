<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Parametri fiscali di riferimento (indicativi), per anno. */
class FiscalParameter extends Model
{
    protected $fillable = ['company_id', 'year', 'ambito', 'deducibilita', 'iva', 'limiti', 'riferimento', 'fonte_url'];
}
