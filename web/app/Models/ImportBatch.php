<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = ['company_id', 'created_by', 'status', 'files', 'invoices', 'duplicates', 'to_review', 'errors', 'started_at', 'finished_at', 'mode'];

    protected function casts(): array
    {
        return ['status' => ImportStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImportItem::class)->orderBy('id');
    }
}
