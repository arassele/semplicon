<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modulo Cespiti (journal_lines). */
class JournalLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['dare' => 'decimal:2', 'avere' => 'decimal:2'];
    }

    public $timestamps = false;

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
