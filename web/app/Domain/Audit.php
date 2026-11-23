<?php

namespace App\Domain;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(
        string $action,
        ?User $user,
        ?Company $company = null,
        ?Model $subject = null,
        ?string $reason = null,
        array $data = [],
    ): AuditEvent {
        // Transazione: la catena viene bloccata sull'ultima voce, le voci parallele si accodano una dopo l'altra.
        return DB::transaction(fn () => AuditEvent::create([
            'company_id' => $company?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'reason' => $reason,
            'data' => $data ?: null,
            'ip_address' => request()?->ip(),
        ]));
    }
}
