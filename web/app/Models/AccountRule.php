<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Regola di imputazione (ticket 09): salva esclusivamente il conto, la quota proviene dall'impostazione del conto. */
class AccountRule extends Model
{
    protected $guarded = ['id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
