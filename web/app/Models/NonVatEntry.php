<?php

namespace App\Models;

use App\Domain\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimento fuori campo IVA (costi o ricavi al di fuori dei Registri IVA). Immutabile; correzione tramite storno. */
class NonVatEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $e) {
            $allowed = $e->getOriginal('status') === 'registrato' && $e->status === 'stornato'
                && array_diff(array_keys($e->getDirty()), ['status', 'updated_at']) === [];
            if (! $allowed) {
                throw new ImmutableRecordException('Un movimento non può essere modificato: usa lo storno.');
            }
        });
        static::deleting(fn () => throw new ImmutableRecordException('Un movimento non può essere eliminato: usa lo storno.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
