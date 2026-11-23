<?php

namespace App\Domain\Cespiti;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Decimal;

/**
 * Prima nota cespiti: scritture immutabili (Dare = Avere), chiave di origine univoca contro la doppia registrazione, correzione solo tramite storno.
 * Viene eseguita sempre all'interno della transazione del chiamante.
 */
final class JournalService
{
    /** @param list<array{account_id: ?int, dare?: int, avere?: int, description?: string}> $lines Cent */
    public function post(Company $c, User $actor, string $sourceKey, string $date, string $description, array $lines): JournalEntry
    {
        $year = (int) substr($date, 0, 4);
        FiscalYears::assertOpen($c, $year);
        $lines = array_values(array_filter($lines, fn ($l) => ($l['dare'] ?? 0) !== 0 || ($l['avere'] ?? 0) !== 0));
        foreach ($lines as $l) {
            if (! $l['account_id']) {
                RuleViolation::fail(['accounts' => 'Conto mancante per la scrittura “'.($l['description'] ?? $description).'”: imposta i conti nella categoria o nel cespite.']);
            }
        }
        $d = array_sum(array_map(fn ($l) => $l['dare'] ?? 0, $lines));
        $a = array_sum(array_map(fn ($l) => $l['avere'] ?? 0, $lines));
        if ($d !== $a || $d === 0) {
            RuleViolation::fail(['journal' => 'Scrittura non quadrata (Dare '.Decimal::fromMinor($d).' / Avere '.Decimal::fromMinor($a).').']);
        }
        if (JournalEntry::where('company_id', $c->id)->where('source_key', $sourceKey)->exists()) {
            RuleViolation::fail(['journal' => 'Questa operazione è già contabilizzata (nessuna doppia contabilizzazione).']);
        }
        Company::whereKey($c->id)->lockForUpdate()->first(); // numerazione progressiva per azienda e anno
        $n = (int) JournalEntry::where('company_id', $c->id)->where('year', $year)->max('number') + 1;
        $e = JournalEntry::create(['company_id' => $c->id, 'year' => $year, 'number' => $n, 'entry_date' => $date, 'description' => mb_substr($description, 0, 255), 'source_key' => $sourceKey,
            'status' => 'contabilizzato', 'created_by' => $actor->id]);
        foreach ($lines as $l) {
            JournalLine::create(['journal_entry_id' => $e->id, 'account_id' => $l['account_id'], 'dare' => Decimal::fromMinor($l['dare'] ?? 0), 'avere' => Decimal::fromMinor($l['avere'] ?? 0),
                'description' => isset($l['description']) ? mb_substr($l['description'], 0, 255) : null]);
        }
        Audit::record('journal.posted', $actor, $c, $e, data: ['number' => "{$n}/{$year}", 'source' => $sourceKey, 'total' => Decimal::fromMinor($d)]);

        return $e;
    }

    /** Storno: registrazione inversa a lati scambiati, l'originale resta visibile («stornato»). */
    public function reverse(Company $c, User $actor, JournalEntry $e, string $date, string $reason): JournalEntry
    {
        if ($e->status !== 'contabilizzato') {
            RuleViolation::fail(['journal' => 'La scrittura è già stornata.']);
        }
        $lines = $e->lines()->get()->map(fn ($l) => ['account_id' => $l->account_id, 'dare' => Decimal::toMinor((string) $l->avere), 'avere' => Decimal::toMinor((string) $l->dare), 'description' => $l->description])->all();
        $r = $this->post($c, $actor, $e->source_key.':storno:'.$e->id, $date, 'Storno: '.$e->description.' – '.$reason, $lines);
        $r->update(['reverses_id' => $e->id]);
        $e->update(['status' => 'stornato']);

        return $r;
    }
}
