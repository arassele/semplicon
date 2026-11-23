<?php

namespace App\Models;

use App\Enums\ContactType;
use Illuminate\Database\Eloquent\Model;

class CompanyContact extends Model
{
    protected $fillable = ['company_id', 'type', 'value', 'description', 'is_default'];

    protected function casts(): array
    {
        return ['type' => ContactType::class, 'is_default' => 'boolean'];
    }
}
