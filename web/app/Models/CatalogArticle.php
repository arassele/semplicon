<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Voce di catalogo di un'azienda (articolo, blocco di testo o unità). */
class CatalogArticle extends Model
{
    protected $table = 'catalog_articles';

    protected $fillable = ['company_id', 'code', 'description', 'unit', 'price', 'rate', 'natura', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'price' => 'decimal:2', 'rate' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
