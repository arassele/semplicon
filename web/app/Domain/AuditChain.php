<?php

namespace App\Domain;

use App\Models\AuditEvent;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Catena di hash sul registro di audit: hash = SHA-256 sull'hash precedente e su tutti i campi della voce. Chi modifica,
 * elimina o inserisce una voce interrompe la catena da quel punto. La catena è separata per azienda (eventi senza azienda: catena propria).
 * Le voci precedenti alla catena (hash = null) vengono contate ma non verificate.
 */
final class AuditChain
{
    /** Forma canonica: chiavi ordinate, affinché PostgreSQL jsonb (che riordina le chiavi) e SQLite producano lo stesso hash. */
    public static function canonical(mixed $v): string
    {
        $sort = function ($x) use (&$sort) {
            if (is_array($x)) {
                if (! array_is_list($x)) {
                    ksort($x);
                }
                foreach ($x as $k => $y) {
                    $x[$k] = $sort($y);
                }
            }

            return $x;
        };

        return (string) json_encode($sort($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function compute(?string $prev, AuditEvent $e): string
    {
        return hash('sha256', self::canonical([
            'prev' => $prev, 'company' => $e->company_id, 'user' => $e->user_id, 'action' => $e->action, 'subject_type' => $e->subject_type,
            'subject_id' => $e->subject_id, 'reason' => $e->reason, 'data' => $e->data, 'ip' => $e->ip_address,
            'at' => $e->created_at?->format('Y-m-d H:i:s'),
        ]));
    }

    /** Viene richiamato alla creazione di una voce (all'interno della stessa transazione; blocca l'ultima voce della catena). */
    public static function seal(AuditEvent $e): void
    {
        $last = AuditEvent::query()->where('company_id', $e->company_id)->whereNotNull('hash')->orderByDesc('id')->lockForUpdate()->first();
        $e->prev_hash = $last?->hash;
        $e->hash = self::compute($e->prev_hash, $e);
    }

    /**
     * @return array{ok: bool, checked: int, legacy: int, head: ?string, broken_id: ?int, reason: ?string}
     */
    public static function verify(?Company $company): array
    {
        $q = AuditEvent::query()->where('company_id', $company?->id)->orderBy('id');
        $legacy = (clone $q)->whereNull('hash')->count();
        $prev = null;
        $checked = 0;
        $fail = null;
        foreach ((clone $q)->whereNotNull('hash')->cursor() as $e) {
            if ($e->prev_hash !== $prev) {
                $fail = [$e->id, 'La catena è interrotta: un evento precedente è stato cancellato, inserito o modificato.'];
                break;
            }
            if (self::compute($e->prev_hash, $e) !== $e->hash) {
                $fail = [$e->id, 'Il contenuto dell’evento è stato modificato.'];
                break;
            }
            $prev = $e->hash;
            $checked++;
        }

        return ['ok' => $fail === null, 'checked' => $checked, 'legacy' => $legacy, 'head' => $prev, 'broken_id' => $fail[0] ?? null, 'reason' => $fail[1] ?? null];
    }

    /** Risigillare la catena di un'azienda (dopo il ripristino di un backup, in cui azienda e utenti sono stati riassegnati). */
    public static function reseal(Company $company): int
    {
        $prev = null;
        $n = 0;
        foreach (DB::table('audit_events')->where('company_id', $company->id)->orderBy('id')->cursor() as $row) {
            $e = (new AuditEvent)->newFromBuilder((array) $row);
            $hash = self::compute($prev, $e);
            DB::table('audit_events')->where('id', $row->id)->update(['prev_hash' => $prev, 'hash' => $hash]);
            $prev = $hash;
            $n++;
        }

        return $n;
    }
}
