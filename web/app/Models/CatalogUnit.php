<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Voce di catalogo di un'azienda (articolo, blocco di testo o unità). */
class CatalogUnit extends Model
{
    protected $table = 'catalog_units';

    protected $fillable = ['company_id', 'code', 'label'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
