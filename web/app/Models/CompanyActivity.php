<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyActivity extends Model
{
    protected $fillable = ['company_id', 'code', 'description', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
