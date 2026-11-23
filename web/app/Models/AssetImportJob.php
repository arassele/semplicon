<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Modulo Cespiti (asset_import_jobs). */
class AssetImportJob extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'report' => 'array'];
    }
}
