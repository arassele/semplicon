<?php

namespace App\Domain\Liquidazioni;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\User;
use App\Models\VatClaim;
use Illuminate\Support\Facades\DB;

/** Pratiche di rimborso (IVA TR, dati per la dichiarazione annuale): Bozza → Richiesto → Erogato | Respinto. */
final class ClaimService
{
    public const STATUS = ['bozza' => 'Bozza', 'richiesto' => 'Richiesto', 'erogato' => 'Erogato', 'respinto' => 'Respinto'];

    public function file(Company $company, User $actor, VatClaim $c, string $date, ?string $protocol): void
    {
        if ($c->status !== 'bozza') {
            RuleViolation::fail(['status' => 'La pratica non è in bozza.']);
        }
        if ($date > now()->toDateString()) {
            RuleViolation::fail(['filed_at' => 'La data non può essere nel futuro.']);
        }
        if ($c->kind === 'tr' && $c->deadline && $date > $c->deadline->toDateString()) {
            RuleViolation::fail(['filed_at' => 'Termine di presentazione del modello TR: '.$c->deadline->format('d/m/Y').'.']);
        }
        $c->update(['status' => 'richiesto', 'filed_at' => $date, 'protocol' => $protocol ?: null]);
        Audit::record('claim.filed', $actor, $company, $c, data: ['date' => $date, 'protocol' => $protocol]);
    }

    public function outcome(Company $company, User $actor, VatClaim $c, string $result, string $date, ?string $notes): void
    {
        DB::transaction(function () use ($company, $actor, $c, $result, $date, $notes) {
            if ($c->status !== 'richiesto' || ! in_array($result, ['erogato', 'respinto'], true)) {
                RuleViolation::fail(['status' => 'Esito registrabile solo per una pratica richiesta.']);
            }
            if ($result === 'respinto' && trim((string) $notes) === '') {
                RuleViolation::fail(['notes' => 'Indica il motivo del rigetto.']);
            }
            $c->update(['status' => $result, 'outcome_at' => $date, 'outcome_notes' => $notes ?: null]);
            $c->movements()->whereIn('status', ['prenotata', 'utilizzata'])->update($result === 'erogato' ? ['status' => 'utilizzata']
                : ['status' => 'sbloccata', 'reason' => 'Sblocco dopo rigetto della pratica n. '.$c->id.': '.trim((string) $notes)]);
            Audit::record('claim.'.$result, $actor, $company, $c, $notes);
        });
    }
}
