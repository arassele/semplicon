<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Voce di catalogo di un'azienda (articolo, blocco di testo o unità). */
class CatalogSnippet extends Model
{
    protected $table = 'catalog_snippets';

    protected $fillable = ['company_id', 'title', 'body', 'kind'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
