<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modulo Cespiti (journal_entries). */
class JournalEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
