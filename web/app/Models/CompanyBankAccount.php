<?php

namespace App\Models;

use App\Support\Validation\Iban;
use Illuminate\Database\Eloquent\Model;

class CompanyBankAccount extends Model
{
    protected $fillable = ['company_id', 'iban', 'bank_name', 'description', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** ABI/CAB per la visualizzazione, derivati dall'IBAN. */
    public function abiCab(): ?array
    {
        return Iban::abiCab($this->iban);
    }
}
