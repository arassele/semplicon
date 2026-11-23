<?php

namespace App\Models;

use App\Domain\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;

/** Prova di un'interrogazione VIES; solo accodabile. */
class ViesCheck extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableRecordException('Il risultato della verifica VIES non è modificabile.'));
    }
}
