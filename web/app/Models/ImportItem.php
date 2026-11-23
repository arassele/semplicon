<?php

namespace App\Models;

use App\Enums\ImportItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportItem extends Model
{
    protected $fillable = ['import_batch_id', 'company_id', 'document_id', 'parent_id', 'filename', 'staging_path', 'status', 'messages', 'invoices', 'results'];

    protected function casts(): array
    {
        return ['status' => ImportItemStatus::class, 'messages' => 'array', 'results' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
