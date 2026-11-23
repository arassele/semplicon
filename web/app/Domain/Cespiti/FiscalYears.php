<?php

namespace App\Domain\Cespiti;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\FiscalYearClosure;
use App\Models\User;

/** Esercizi chiusi (Cespiti e Prima nota cespiti): nessun movimento, esecuzione o modifica di aliquote nell'anno chiuso; riapertura con motivazione. */
final class FiscalYears
{
    public static function isClosed(Company $c, int $year): bool
    {
        return FiscalYearClosure::where('company_id', $c->id)->where('year', $year)->whereNull('reopened_at')->exists();
    }

    public static function assertOpen(Company $c, int $year, ?array &$errors = null, string $field = 'date'): void
    {
        if (! self::isClosed($c, $year)) {
            return;
        }
        $msg = "L’esercizio {$year} è chiuso: riaprilo (Cespiti › Impostazioni) con un motivo prima di modificarlo.";
        if ($errors !== null) {
            $errors[$field] = $msg;

            return;
        }
        RuleViolation::fail([$field => $msg]);
    }

    public static function close(Company $c, User $actor, int $year): void
    {
        if (self::isClosed($c, $year)) {
            RuleViolation::fail(['year' => "L’esercizio {$year} è già chiuso."]);
        }
        $open = $c->assetDepreciationRuns()->where('year', $year)->where('status', 'calcolato')->exists();
        if ($open) {
            RuleViolation::fail(['year' => "Il calcolo ammortamenti {$year} è ancora da contabilizzare."]);
        }
        FiscalYearClosure::updateOrCreate(['company_id' => $c->id, 'year' => $year], ['closed_at' => now(), 'closed_by' => $actor->id, 'reopened_at' => null, 'reopened_by' => null, 'reopen_reason' => null]);
        Audit::record('fiscal_year.closed', $actor, $c, data: ['year' => $year]);
    }

    public static function reopen(Company $c, User $actor, int $year, string $reason): void
    {
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo della riapertura.']);
        }
        $row = FiscalYearClosure::where('company_id', $c->id)->where('year', $year)->whereNull('reopened_at')->first();
        if (! $row) {
            RuleViolation::fail(['year' => "L’esercizio {$year} non è chiuso."]);
        }
        $row->update(['reopened_at' => now(), 'reopened_by' => $actor->id, 'reopen_reason' => trim($reason)]);
        Audit::record('fiscal_year.reopened', $actor, $c, reason: $reason, data: ['year' => $year]);
    }
}
