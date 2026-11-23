<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** File XML generato (operazioni transfrontaliere), versionato; generare e scaricare non registrano nulla. */
class XmlExport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'downloaded_at' => 'datetime'];
    }
}
