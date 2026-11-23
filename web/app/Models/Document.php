<?php

namespace App\Models;

use App\Domain\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** File originale, invariato nell'archivio privato (disco «local», storage/app/private). */
class Document extends Model
{
    protected $fillable = ['company_id', 'original_name', 'sha256', 'size', 'kind', 'storage_path', 'uploaded_by'];

    protected static function booted(): void
    {
        // Gli originali restano invariati: contenuto e posizione di archiviazione sono definitivi dopo la creazione.
        static::updating(function (self $d) {
            if (array_intersect(array_keys($d->getDirty()), ['sha256', 'storage_path', 'size', 'company_id'])) {
                throw new ImmutableRecordException('Il documento originale non può essere modificato.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
