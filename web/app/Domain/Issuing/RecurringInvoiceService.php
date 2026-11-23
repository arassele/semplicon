<?php

namespace App\Domain\Issuing;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Fatture ricorrenti (elenco requisiti 2.1). Un modello memorizza il contenuto del modulo; alla scadenza si emette seguendo lo stesso percorso
 * della mano (numero progressivo, FatturaPA, Registro vendite, Scadenziario). O l'utente avvia «Emetti ora»
 * oppure – con auto_issue – l'esecuzione giornaliera `semplicon:recurring-run`. Per esecuzione al massimo una fattura per modello.
 * Segnaposto in causale e testi delle righe: {mese} (mese della data fattura), {anno}, {periodo} (periodo del ciclo).
 */
final class RecurringInvoiceService
{
    public const FREQUENCIES = ['mensile' => 1, 'trimestrale' => 3, 'semestrale' => 6, 'annuale' => 12];

    private const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    public function __construct(private InvoiceIssuer $issuer) {}

    /** @param  array<string, mixed>  $d  name, frequency, start_date, end_date, auto_issue, payment_days, payload (contenuto del modulo come per l'emissione) */
    public function create(Company $company, User $actor, array $d): RecurringInvoice
    {
        $e = [];
        if (! isset(self::FREQUENCIES[$d['frequency'] ?? ''])) {
            $e['recurring.frequency'] = 'Scegli la frequenza.';
        }
        $start = (string) ($d['start_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || strtotime($start) === false) {
            $e['recurring.start_date'] = 'Indica la data della prima emissione.';
        }
        $end = trim((string) ($d['end_date'] ?? '')) ?: null;
        if ($end && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start)) {
            $e['recurring.end_date'] = 'La data finale non può precedere la prima emissione.';
        }
        $payload = $this->cleanPayload((array) ($d['payload'] ?? []));
        // Segnalare gli errori del modulo (righe, cliente …) come nell'emissione: prova con la data odierna e una rata
        $probe = $this->materialize($payload, now()->toDateString(), 30, $company);
        $q = $this->issuer->quote($company, $probe);
        $e = [...$e, ...array_filter($q['errors'], fn ($k) => ! in_array($k, ['doc_date'], true), ARRAY_FILTER_USE_KEY)];
        if ($e) {
            RuleViolation::fail($e);
        }
        $r = $company->recurringInvoices()->create([
            'name' => mb_substr(trim((string) ($d['name'] ?? '')) ?: ($payload['customer']['name'] ?? 'Fattura ricorrente'), 0, 80), 'frequency' => $d['frequency'], 'next_date' => $start, 'anchor_day' => (int) substr($start, 8, 2),
            'end_date' => $end, 'active' => true, 'runs' => 0, 'auto_issue' => (bool) ($d['auto_issue'] ?? false), 'payment_days' => max(0, min(365, (int) ($d['payment_days'] ?? 30))), 'payload' => $payload, 'created_by' => $actor->id,
        ]);
        Audit::record('recurring.created', $actor, $company, $r, data: ['name' => $r->name, 'frequency' => $r->frequency, 'next_date' => $start, 'auto' => $r->auto_issue]);

        return $r;
    }

    public function setActive(Company $company, User $actor, RecurringInvoice $r, bool $active): void
    {
        $r->update(['active' => $active]);
        Audit::record($active ? 'recurring.resumed' : 'recurring.paused', $actor, $company, $r, data: ['name' => $r->name]);
    }

    public function delete(Company $company, User $actor, RecurringInvoice $r): void
    {
        Audit::record('recurring.deleted', $actor, $company, $r, data: ['name' => $r->name]);
        $r->delete();
    }

    public function isDue(RecurringInvoice $r, ?string $today = null): bool
    {
        $today ??= now()->toDateString();

        return $r->active && $r->next_date->toDateString() <= $today && (! $r->end_date || $r->next_date->toDateString() <= $r->end_date->toDateString());
    }

    /** Emette la fattura in scadenza e fa avanzare il modello di un ciclo. Gli errori finiscono in last_error (il modello resta in scadenza). */
    public function issueNow(Company $company, User $actor, RecurringInvoice $r): Invoice
    {
        if (! $this->isDue($r)) {
            RuleViolation::fail(['recurring' => 'Questa fattura ricorrente non è ancora scaduta.']);
        }
        // Data fattura: oggi, ma mai prima dell'ultima fattura emessa (i numeri seguono le date)
        $date = now()->toDateString();
        $period = $this->periodLabel($r);
        try {
            $data = $this->materialize($r->payload, $date, $r->payment_days, $company, $period);
            $invoice = $this->issuer->issue($company, $actor, $data);
        } catch (ValidationException $e) {
            $msg = implode(' ', collect($e->errors())->flatten()->all());
            $r->update(['last_error' => mb_substr($msg, 0, 1000)]);
            Audit::record('recurring.failed', $actor, $company, $r, data: ['name' => $r->name, 'error' => mb_substr($msg, 0, 300)]);
            throw $e;
        }
        $r->update(['next_date' => $this->advance($r->next_date, $r->anchor_day, self::FREQUENCIES[$r->frequency])->toDateString(), 'runs' => $r->runs + 1, 'last_run_at' => now(),
            'last_invoice_id' => $invoice->id, 'last_error' => null]);
        Audit::record('recurring.issued', $actor, $company, $r, data: ['name' => $r->name, 'invoice' => $invoice->number, 'next_date' => $r->next_date->toDateString()]);

        return $invoice;
    }

    /** Esecuzione automatica: per modello con auto_issue al massimo una fattura in scadenza. @return array{issued: int, failed: int} */
    public function runAuto(): array
    {
        $issued = $failed = 0;
        $q = RecurringInvoice::with('company')->where('active', true)->where('auto_issue', true)->where('next_date', '<=', now()->toDateString())->orderBy('company_id')->orderBy('id');
        foreach ($q->get() as $r) {
            $actor = $r->creator ?? $r->company->owners()->first();
            if (! $actor || ! $this->isDue($r)) {
                continue;
            }
            try {
                $this->issueNow($r->company, $actor, $r);
                $issued++;
            } catch (ValidationException) {
                $failed++;
            }
        }

        return ['issued' => $issued, 'failed' => $failed];
    }

    public function advance(CarbonImmutable|\DateTimeInterface $from, int $anchorDay, int $months): CarbonImmutable
    {
        $d = CarbonImmutable::parse($from)->startOfMonth()->addMonthsNoOverflow($months);

        return $d->day(min($anchorDay, $d->daysInMonth));
    }

    /** Periodo del ciclo, es. «novembre 2026» o «novembre 2026 – gennaio 2027». */
    public function periodLabel(RecurringInvoice $r): string
    {
        $n = self::FREQUENCIES[$r->frequency];
        $a = CarbonImmutable::parse($r->next_date);
        $label = fn (CarbonImmutable $x) => self::MONTHS[$x->month - 1].' '.$x->year;
        if ($n === 1) {
            return $label($a);
        }

        return $label($a).' – '.$label($a->addMonthsNoOverflow($n - 1));
    }

    /** Recepisce solo i campi del modulo pertinenti all'emissione (nessuna indicazione di date e rate). */
    private function cleanPayload(array $p): array
    {
        return array_intersect_key($p, array_flip(['doc_type', 'regime', 'account_id', 'payment_mode', 'causale', 'bollo', 'split', 'ref_normativo', 'customer', 'lines', 'apply_ritenuta', 'apply_casse']));
    }

    /** Dati del modulo per l'emissione: data, segnaposto, una rata secondo payment_days. */
    private function materialize(array $payload, string $date, int $paymentDays, Company $company, ?string $period = null): array
    {
        $month = self::MONTHS[(int) substr($date, 5, 2) - 1];
        $year = substr($date, 0, 4);
        $sub = fn (string $t) => str_replace(['{mese}', '{anno}', '{periodo}'], [$month, $year, $period ?? "{$month} {$year}"], $t);
        $payload['causale'] = $sub((string) ($payload['causale'] ?? ''));
        $payload['lines'] = array_map(fn ($l) => [...$l, 'description' => $sub((string) ($l['description'] ?? ''))], (array) ($payload['lines'] ?? []));
        $payload['doc_type'] = $payload['doc_type'] ?? 'TD01';
        $payload['doc_date'] = $date;
        $payments = [];
        if ($payload['doc_type'] !== 'TD04') {
            $quote = $this->issuer->quote($company, [...$payload, 'payments' => []]);
            $payments = [['date' => CarbonImmutable::parse($date)->addDays($paymentDays)->toDateString(), 'amount' => Decimal::normalize($quote['payable'])]];
        }

        return [...$payload, 'payments' => $payments];
    }
}
