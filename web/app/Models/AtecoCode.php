<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtecoCode extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'title_it', 'active', 'source'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
