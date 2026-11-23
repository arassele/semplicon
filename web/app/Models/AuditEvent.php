<?php

namespace App\Models;

use App\Domain\AuditChain;
use App\Domain\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solo accodabile: le voci di audit non vengono mai modificate né eliminate. */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'user_id', 'action', 'subject_type', 'subject_id', 'reason', 'data', 'ip_address', 'prev_hash', 'hash'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $e) {
            $e->created_at ??= now()->startOfSecond();
            AuditChain::seal($e);
        });
        static::updating(fn () => throw new ImmutableRecordException('Il registro attività non è modificabile.'));
        static::deleting(fn () => throw new ImmutableRecordException('Il registro attività non è modificabile.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
