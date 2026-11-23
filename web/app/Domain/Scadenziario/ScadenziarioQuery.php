<?php

namespace App\Domain\Scadenziario;

use App\Domain\Liquidazioni\ClaimService;
use App\Domain\Liquidazioni\F24Service;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\FiscalEntry;
use App\Models\Installment;
use App\Models\MoneyMovement;
use App\Models\VatClaim;
use App\Models\VatSettlement;
use App\Support\Decimal;

/**
 * Vista di lettura dello Scadenziario: rate con stato di pagamento e scadenza, incassi/pagamenti e panoramica fiscale.
 * Tutte le query passano per l'azienda (separazione dei mandanti); riproducibile con data di riferimento (i pagamenti successivi alla data non contano).
 */
final class ScadenziarioQuery
{
    public const STATUS = ['aperte' => 'Da saldare', 'aperta' => 'Aperta', 'parziale' => 'Parzialmente saldata', 'saldata' => 'Saldata', 'da_verificare' => 'Da verificare', 'tutte' => 'Tutte'];

    /**
     * @param  array{view?: string, party?: ?string, period?: ?string, from?: ?string, to?: ?string, status?: ?string, overdue?: mixed, as_of?: ?string}  $f
     * @return list<array<string, mixed>>
     */
    public function installments(Company $company, array $f): array
    {
        $sale = ($f['view'] ?? 'da-incassare') !== 'da-pagare';
        $asOf = ($f['as_of'] ?? '') ?: null;
        $from = ($f['from'] ?? '') ?: null;
        $to = ($f['to'] ?? '') ?: null;
        $byPayment = ($f['period'] ?? 'scadenza') === 'pagamento';
        $status = ($f['status'] ?? '') ?: 'aperte';
        $party = mb_strtolower(trim((string) ($f['party'] ?? '')));

        $q = Installment::where('installments.company_id', $company->id)
            ->whereHas('invoice', function ($w) use ($sale, $asOf) {
                $w->where('direction', $sale ? InvoiceDirection::Vendita : InvoiceDirection::Acquisto)->where('status', '!=', InvoiceStatus::Scartata);
                if ($asOf) {
                    $w->where('doc_date', '<=', $asOf); // le fatture successive alla data di riferimento non esistevano ancora
                }
            })
            ->with('invoice')->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('invoice_id')->orderBy('seq');
        if (! $byPayment && $from) {
            $q->where('due_date', '>=', $from);
        }
        if (! $byPayment && $to) {
            $q->where('due_date', '<=', $to);
        }

        $rows = [];
        foreach ($q->get() as $i) {
            $inv = $i->invoice;
            $name = ScheduleService::partyName($inv);
            if ($party !== '' && ! str_contains(mb_strtolower($name.' '.ScheduleService::partyKey($inv)), $party)) {
                continue;
            }
            $allocs = Ledger::validAllocations($i, $asOf);
            if ($byPayment && ! $allocs->contains(fn ($a) => (! $from || $a->allocation_date->toDateString() >= $from) && (! $to || $a->allocation_date->toDateString() <= $to))) {
                continue;
            }
            $s = Ledger::installmentState($i, $asOf);
            $paid = $inv->payment_check && $s['residual'] > 0 ? 'da_verificare' : $s['paid'];
            $keep = match ($status) {
                'aperte' => $s['residual'] > 0,
                'tutte' => true,
                default => $paid === $status,
            };
            if (! $keep || (! empty($f['overdue']) && $s['due'] !== 'scaduta')) {
                continue;
            }
            $rows[] = $this->row($i, $s, $paid, $allocs);
        }

        return $rows;
    }

    public function row(Installment $i, array $s, string $paid, $allocs): array
    {
        $inv = $i->invoice;
        $sale = $inv->direction === InvoiceDirection::Vendita;

        return [
            'id' => $i->id, 'seq' => $i->seq, 'rates' => $inv->installments()->count(), 'invoice_id' => $inv->id, 'number' => $inv->number, 'doc_date' => $inv->doc_date->toDateString(),
            'doc_type' => $inv->doc_type, 'credit_note' => $inv->doc_type === 'TD04', 'sale' => $sale,
            'party' => ScheduleService::partyName($inv), 'party_key' => ScheduleService::partyKey($inv),
            'due_date' => $i->due_date?->toDateString(), 'payment_mode' => $i->payment_mode, 'source' => $i->source, 'edited' => $i->edited,
            'amount' => Decimal::fromMinor($s['amount']), 'settled' => Decimal::fromMinor($s['settled']), 'residual' => Decimal::fromMinor($s['residual']),
            'paid' => $paid, 'paid_label' => Ledger::paidLabel($paid, $sale), 'due' => $s['due'], 'due_label' => Ledger::DUE_LABELS[$s['due']] ?? $s['due'],
            'amount_check' => (bool) $inv->amount_check,
            'payments' => $allocs->map(fn ($a) => $this->allocation($a, $i->id))->values()->all(),
        ];
    }

    public function allocation($a, int $forInstallment): array
    {
        $a->loadMissing('movement', 'installment.invoice', 'creditInstallment.invoice');
        $credit = $a->type === 'nota_credito';
        // Dal punto di vista della nota di credito la fattura compensata è la controparte.
        $other = $credit ? ($a->credit_installment_id === $forInstallment ? $a->installment : $a->creditInstallment) : null;

        return ['id' => $a->id, 'type' => $a->type, 'date' => $a->allocation_date->toDateString(), 'amount' => (string) $a->amount, 'movement_id' => $a->movement_id,
            'movement_amount' => $a->movement ? (string) $a->movement->amount : null, 'method' => $a->movement?->method, 'bank_ref' => $a->movement?->bank_ref,
            'payer_date' => $a->movement?->payer_date?->toDateString(),
            'other_invoice_id' => $other?->invoice_id, 'other_number' => $other?->invoice?->number];
    }

    /**
     * Incassi e pagamenti (movimenti di denaro) con le loro attribuzioni.
     *
     * @return list<array<string, mixed>>
     */
    public function movements(Company $company, array $f): array
    {
        $party = mb_strtolower(trim((string) ($f['party'] ?? '')));
        $q = MoneyMovement::where('company_id', $company->id)->with(['allocations' => fn ($a) => $a->with('installment.invoice'), 'creator'])
            ->orderByDesc('movement_date')->orderByDesc('id');
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (! empty($f[$k])) {
                $q->where('movement_date', $op, $f[$k]);
            }
        }
        if (! empty($f['as_of'])) {
            $q->where('movement_date', '<=', $f['as_of']);
        }
        if (in_array($f['kind'] ?? '', ['incasso', 'pagamento'], true)) {
            $q->where('kind', $f['kind']);
        }
        if ($party !== '') {
            $q->where(fn ($w) => $w->whereRaw('lower(party_name) like ?', ["%{$party}%"])->orWhereRaw('lower(party_key) like ?', ["%{$party}%"]));
        }

        return $q->get()->map(function (MoneyMovement $m) {
            $valid = $m->allocations->where('status', 'valida');
            $allocated = $valid->sum(fn ($a) => Decimal::toMinor((string) $a->amount));

            return ['id' => $m->id, 'kind' => $m->kind, 'date' => $m->movement_date->toDateString(), 'payer_date' => $m->payer_date?->toDateString(), 'payer_year_rule' => $m->payer_year_rule,
                'party' => $m->party_name, 'party_key' => $m->party_key, 'amount' => (string) $m->amount, 'method' => $m->method, 'bank_ref' => $m->bank_ref, 'notes' => $m->notes,
                'status' => $m->status, 'reversal_reason' => $m->reversal_reason, 'created_by' => $m->creator?->name,
                'allocated' => Decimal::fromMinor($allocated), 'unallocated' => $m->status === 'valida' ? Decimal::fromMinor(Decimal::toMinor((string) $m->amount) - $allocated) : '0.00',
                'allocations' => $m->allocations->map(fn ($a) => ['id' => $a->id, 'amount' => (string) $a->amount, 'status' => $a->status, 'reversal_reason' => $a->reversal_reason,
                    'installment_id' => $a->installment_id, 'seq' => $a->installment->seq, 'invoice_id' => $a->installment->invoice_id, 'number' => $a->installment->invoice->number])->values()->all()];
        })->all();
    }

    /**
     * Riepilogo fiscale per cassa di un anno d'imposta: per documento le quote attribuite con motivazione e le verifiche aperte.
     *
     * @return array{year: int, mode: array, totals: array<string, string>, documents: list<array<string, mixed>>, checks: list<array<string, mixed>>}
     */
    public function fiscalSummary(Company $company, int $year): array
    {
        $entries = FiscalEntry::where('company_id', $company->id)->where('year', $year)->with('invoice', 'account')->orderBy('event_date')->orderBy('id')->get();
        $tot = ['ricavo' => 0, 'costo' => 0, 'ricavo_fiscale' => 0, 'costo_fiscale' => 0, 'da_verificare' => 0];
        $docs = [];
        foreach ($entries as $e) {
            $c = Decimal::toMinor((string) $e->amount);
            $fc = Decimal::toMinor((string) $e->fiscal_amount);
            if ($e->basis === 'da_verificare') {
                $tot['da_verificare'] += $c;
            } else {
                $tot[$e->nature] += $c;
                $tot[$e->nature.'_fiscale'] += $fc;
            }
            $d = &$docs[$e->invoice_id];
            $d ??= ['invoice_id' => $e->invoice_id, 'number' => $e->invoice->number, 'doc_date' => $e->invoice->doc_date->toDateString(), 'doc_type' => $e->invoice->doc_type,
                'party' => ScheduleService::partyName($e->invoice), 'nature' => $e->nature, 'amount' => 0, 'fiscal' => 0, 'check' => false, 'entries' => []];
            $d['amount'] += $c;
            $d['fiscal'] += $fc;
            $d['check'] = $d['check'] || $e->basis === 'da_verificare';
            $d['entries'][] = ['date' => $e->event_date->toDateString(), 'account' => $e->account ? $e->account->code.' '.$e->account->name : null, 'basis' => $e->basis,
                'amount' => (string) $e->amount, 'fiscal_amount' => (string) $e->fiscal_amount, 'reason' => $e->reason, 'allocation_id' => $e->allocation_id];
            unset($d);
        }
        $docs = array_values(array_map(fn ($d) => [...$d, 'amount' => Decimal::fromMinor($d['amount']), 'fiscal' => Decimal::fromMinor($d['fiscal'])], $docs));
        $checks = [];
        foreach ($company->invoices()->where(fn ($w) => $w->where('payment_check', true)->orWhere('amount_check', true))->whereYear('doc_date', '<=', $year)->orderBy('doc_date')->get() as $i) {
            $checks[] = ['invoice_id' => $i->id, 'number' => $i->number, 'party' => $i->direction ? ScheduleService::partyName($i) : $i->supplier_name,
                'reason' => $i->payment_check ? 'Stato pagamento da verificare' : 'Importo da verificare'];
        }

        return ['year' => $year, 'mode' => FiscalService::modeFor($company, $year), 'totals' => array_map(fn ($v) => Decimal::fromMinor($v), $tot), 'documents' => $docs, 'checks' => $checks];
    }

    /** Versamenti IVA e pratiche TR con stato (Scadenze fiscali, Panoramica). */
    public function fiscalDeadlines(Company $company): array
    {
        $items = [];
        $latest = VatSettlement::where('company_id', $company->id)->where('status', 'confermata')->orderBy('year')->orderBy('period')->get();
        foreach ($latest as $s) {
            $f = F24Service::active($s);
            $due = Decimal::toMinor((string) $s->amount_due);
            $state = $due > 0 ? ($f && in_array($f->status, ['presentato', 'addebito_confermato'], true) ? 'chiusa' : 'aperta') : 'chiusa';
            $items[] = ['type' => 'liquidazione', 'date' => $s->due_date?->toDateString(), 'label' => 'Versamento IVA '.$s->data['period_label'], 'year' => $s->year, 'period' => $s->period,
                'amount' => (string) $s->amount_due, 'code' => $s->data['code']['code'] ?? null, 'state' => $state, 'review' => $s->review_required,
                'detail' => $due > 0 ? ($f ? 'Delega F24 n. '.$f->id.': '.F24Service::STATUS[$f->status].(Decimal::toMinor((string) $f->balance) === 0 ? ' (saldo zero)' : '') : 'Delega F24 da generare')
                    : ($s->result === 'debito' ? 'Debito di '.str_replace('.', ',', (string) $s->deferred).' € rinviato al periodo successivo' : ($s->result === 'credito' ? 'Chiusa a credito, senza F24' : 'Chiusa a zero, senza F24')),
                'f24_id' => $f?->id];
        }
        foreach (VatClaim::where('company_id', $company->id)->where('kind', 'tr')->get() as $c) {
            $items[] = ['type' => 'tr', 'date' => $c->deadline?->toDateString(), 'label' => "Modello IVA TR {$c->quarter}° trimestre {$c->year}", 'year' => $c->year, 'period' => null,
                'amount' => Decimal::fromMinor(Decimal::toMinor((string) $c->refund_amount) + Decimal::toMinor((string) $c->compensation_amount)),
                'code' => null, 'state' => $c->status === 'bozza' ? 'aperta' : 'chiusa', 'review' => false, 'detail' => 'Pratica n. '.$c->id.': '.ClaimService::STATUS[$c->status], 'f24_id' => null];
        }
        usort($items, fn ($a, $b) => [$a['state'] === 'aperta' ? 0 : 1, $a['date'] ?? '9999'] <=> [$b['state'] === 'aperta' ? 0 : 1, $b['date'] ?? '9999']);

        return $items;
    }
}
