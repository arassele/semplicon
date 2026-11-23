<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\Scadenziario\FiscalCalendar;
use App\Domain\Scadenziario\FiscalService;
use App\Domain\Scadenziario\Ledger;
use App\Domain\Scadenziario\PaymentService;
use App\Domain\Scadenziario\ScadenziarioQuery;
use App\Domain\Scadenziario\ScheduleService;
use App\Enums\CompanyType;
use App\Enums\InvoiceDirection;
use App\Http\Middleware\ActiveAccountingYear;
use App\Models\Allocation;
use App\Models\Company;
use App\Models\FiscalEntry;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\MoneyMovement;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Scadenziario: Da incassare, Da pagare, Incassi e pagamenti, Riepilogo fiscale per cassa. */
class ScadenziarioController extends Controller
{
    public const PAYMENT_MODES = ['MP01' => 'Contanti', 'MP02' => 'Assegno', 'MP03' => 'Assegno circolare', 'MP05' => 'Bonifico', 'MP08' => 'Carta di pagamento',
        'MP12' => 'RIBA', 'MP19' => 'SEPA Direct Debit', 'MP23' => 'PagoPA'];

    public function __construct(private ScadenziarioQuery $query) {}

    private function filters(Request $request): array
    {
        $date = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k)) ? $request->query($k) : null;

        return [
            'party' => mb_substr(trim((string) $request->query('partner', '')), 0, 100),
            'period' => $request->query('periodo') === 'pagamento' ? 'pagamento' : 'scadenza',
            'from' => $date('dal'), 'to' => $date('al'), 'as_of' => $date('al_giorno'),
            'status' => array_key_exists((string) $request->query('stato'), ScadenziarioQuery::STATUS) ? $request->query('stato') : 'aperte',
            'overdue' => $request->boolean('scadute'),
            'kind' => in_array($request->query('tipo'), ['incasso', 'pagamento'], true) ? $request->query('tipo') : null,
        ];
    }

    public function index(Request $request, Company $company, string $view): Response
    {
        $f = $this->filters($request) + ['view' => $view];
        $rows = $this->query->installments($company, $f);
        $sum = fn ($k) => Decimal::fromMinor(array_sum(array_map(fn ($r) => Decimal::toMinor($r[$k]) * ($r['credit_note'] ? -1 : 1), $rows)));

        return Inertia::render('Scadenziario/Index', [
            'view' => $view, 'filters' => $f, 'rows' => $rows, 'statuses' => ScadenziarioQuery::STATUS,
            'totals' => ['amount' => $sum('amount'), 'settled' => $sum('settled'), 'residual' => $sum('residual'),
                'overdue' => Decimal::fromMinor(array_sum(array_map(fn ($r) => $r['due'] === 'scaduta' && ! $r['credit_note'] ? Decimal::toMinor($r['residual']) : 0, $rows)))],
            'modes' => self::PAYMENT_MODES, 'today' => now()->toDateString(), 'professionista' => $company->tipologia === CompanyType::Professionista,
            'unallocated' => $this->unallocated($company, $view === 'da-incassare' ? 'incasso' : 'pagamento'),
        ]);
    }

    public function movements(Request $request, Company $company): Response
    {
        $f = $this->filters($request);

        return Inertia::render('Scadenziario/Movements', ['filters' => $f, 'rows' => $this->query->movements($company, $f), 'modes' => self::PAYMENT_MODES]);
    }

    public function fiscal(Request $request, Company $company): Response
    {
        $first = $company->invoices()->min('doc_date');
        $years = FiscalEntry::where('company_id', $company->id)->distinct()->pluck('year')->when($company->currentYearSelectable(), fn ($c) => $c->push((int) now()->year))
            ->merge($first ? range((int) substr((string) $first, 0, 4), (int) now()->year) : [])->unique()->sortDesc()->values();
        $year = in_array((int) $request->query('anno'), $years->all(), true) ? (int) $request->query('anno') : (int) now()->year;

        return Inertia::render('Scadenziario/Fiscal', ['year' => $year, 'years' => $years, 'data' => $this->query->fiscalSummary($company, $year),
            'openAtYearEnd' => [
                'sale' => $this->openTotal($company, 'da-incassare', "{$year}-12-31"),
                'purchase' => $this->openTotal($company, 'da-pagare', "{$year}-12-31"),
            ]]);
    }

    private function openTotal(Company $company, string $view, string $asOf): string
    {
        $rows = $this->query->installments($company, ['view' => $view, 'as_of' => $asOf]);

        return Decimal::fromMinor(array_sum(array_map(fn ($r) => Decimal::toMinor($r['residual']) * ($r['credit_note'] ? -1 : 1), $rows)));
    }

    private function unallocated(Company $company, string $kind): array
    {
        return MoneyMovement::where('company_id', $company->id)->where('kind', $kind)->where('status', 'valida')->with('allocations')->orderBy('movement_date')->get()
            ->map(fn ($m) => ['id' => $m->id, 'date' => $m->movement_date->toDateString(), 'party' => $m->party_name, 'party_key' => $m->party_key, 'amount' => (string) $m->amount,
                'unallocated' => Decimal::fromMinor(PaymentService::unallocatedCents($m))])
            ->filter(fn ($m) => $m['unallocated'] !== '0.00')->values()->all();
    }

    public function csv(Request $request, Company $company, string $view): HttpResponse
    {
        $f = $this->filters($request) + ['view' => $view];
        $it = fn ($v) => $v === null ? '' : str_replace('.', ',', (string) $v);
        $d = fn ($v) => $v ? implode('/', array_reverse(explode('-', $v))) : '';
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        if ($view === 'incassi-e-pagamenti') {
            fputcsv($out, ['Data', 'Tipo', 'Cliente/Fornitore', 'Importo', 'Abbinato', 'Da abbinare', 'Modalità', 'Riferimento', 'Data pagamento sostituto', 'Stato', 'Fatture'], ';');
            foreach ($this->query->movements($company, $f) as $m) {
                fputcsv($out, [$d($m['date']), $m['kind'] === 'incasso' ? 'Incasso' : 'Pagamento', $m['party'], $it($m['amount']), $it($m['allocated']), $it($m['unallocated']),
                    $m['method'], $m['bank_ref'], $d($m['payer_date']), $m['status'] === 'valida' ? 'Valido' : 'Stornato: '.$m['reversal_reason'],
                    implode(', ', array_map(fn ($a) => $a['number'].' rata '.$a['seq'].' '.$it($a['amount']).($a['status'] === 'valida' ? '' : ' (stornato)'), $m['allocations']))], ';');
            }
        } else {
            fputcsv($out, ['Cliente/Fornitore', 'Fattura', 'Data fattura', 'Rata', 'Scadenza', 'Importo rata', 'Saldato', 'Residuo', 'Stato pagamento', 'Scadenza residuo', 'Modalità'], ';');
            foreach ($this->query->installments($company, $f) as $r) {
                fputcsv($out, [$r['party'], $r['number'].($r['credit_note'] ? ' (nota di credito)' : ''), $d($r['doc_date']), $r['seq'].'/'.$r['rates'], $r['due_date'] ? $d($r['due_date']) : 'Da definire',
                    $it($r['amount']), $it($r['settled']), $it($r['residual']), $r['paid_label'], $r['due_label'], $r['payment_mode']], ';');
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        Audit::record('scadenziario.exported', $request->user(), $company, data: ['view' => $view, 'filters' => $f]);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="scadenziario-'.$view.($f['as_of'] ? '-al-'.$f['as_of'] : '').'.csv"']);
    }

    public function store(Request $request, Company $company, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate([
            'kind' => ['required', 'in:incasso,pagamento'],
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'method' => ['nullable', 'string', 'max:40'],
            'bank_ref' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'payer_date' => ['nullable', 'date_format:Y-m-d'],
            'payer_year_rule' => ['nullable', 'boolean'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.installment_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
        ], ['amount.regex' => 'Importo non valido (es. 610,00).', 'allocations.*.amount.regex' => 'Importo non valido.', 'allocations.required' => 'Abbina il movimento ad almeno una rata.'],
            ['movement_date' => 'data', 'amount' => 'importo', 'payer_date' => 'data di pagamento del sostituto']);
        $m = $payments->record($company, $request->user(), $data);
        $label = $m->kind === 'incasso' ? 'Incasso' : 'Pagamento';

        return back()->with('success', $m->replayed ? "{$label} già registrato (invio ripetuto): nessun duplicato." : "{$label} di ".str_replace('.', ',', (string) $m->amount).' € registrato.'
            .(PaymentService::unallocatedCents($m) > 0 ? ' Importo da abbinare: '.str_replace('.', ',', Decimal::fromMinor(PaymentService::unallocatedCents($m))).' €.' : ''));
    }

    public function allocate(Request $request, Company $company, MoneyMovement $movement, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['allocations' => ['required', 'array', 'min:1'], 'allocations.*.installment_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/']]);
        $payments->allocate($company, $movement, $request->user(), $data['allocations']);

        return back()->with('success', 'Importo abbinato.');
    }

    public function reverse(Request $request, Company $company, MoneyMovement $movement, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        $payments->reverse($company, $movement, $request->user(), $data['reason']);

        return back()->with('success', ($movement->kind === 'incasso' ? 'Incasso' : 'Pagamento').' stornato: residuo e imputazione fiscale ricalcolati.');
    }

    public function reverseAllocation(Request $request, Company $company, Allocation $allocation, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        $payments->reverseAllocation($company, $allocation, $request->user(), $data['reason']);

        return back()->with('success', 'Abbinamento stornato: residuo e imputazione fiscale ricalcolati.');
    }

    public function compensate(Request $request, Company $company, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['installment_id' => ['required', 'integer'], 'credit_installment_id' => ['required', 'integer'],
            'amount' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'], 'date' => ['required', 'date_format:Y-m-d']], [], ['amount' => 'importo', 'date' => 'data']);
        $payments->compensate($company, $request->user(), (int) $data['installment_id'], (int) $data['credit_installment_id'], $data['amount'], $data['date']);

        return back()->with('success', 'Nota di credito compensata: nessun movimento bancario registrato.');
    }

    public function updateInstallments(Request $request, Company $company, Invoice $invoice, ScheduleService $schedule): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['rows' => ['required', 'array', 'min:1', 'max:60'], 'rows.*.id' => ['nullable', 'integer'], 'rows.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.amount' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'], 'rows.*.payment_mode' => ['nullable', 'string', 'max:4']],
            ['rows.*.amount.regex' => 'Importo non valido.'], ['rows.*.due_date' => 'scadenza', 'rows.*.amount' => 'importo']);
        $schedule->update($company, $invoice, $request->user(), $data['rows']);

        return back()->with('success', 'Scadenze aggiornate.');
    }

    public function confirm(Request $request, Company $company, Invoice $invoice, ScheduleService $schedule): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $schedule->confirm($company, $invoice, $request->user());

        return back()->with('success', 'Stato pagamento confermato.');
    }

    /** Dati per il pannello «Scadenze e pagamenti» della fattura. */
    public static function panel(Company $company, Invoice $invoice): array
    {
        $invoice->load('installments');
        $q = app(ScadenziarioQuery::class);
        $sale = $invoice->direction === InvoiceDirection::Vendita;
        $state = Ledger::invoiceState($invoice);
        $rates = $invoice->installments->map(function (Installment $i) use ($q, $invoice) {
            $i->setRelation('invoice', $invoice);
            $s = Ledger::installmentState($i);

            return $q->row($i, $s, $invoice->payment_check && $s['residual'] > 0 ? 'da_verificare' : $s['paid'], Ledger::validAllocations($i));
        })->values()->all();
        $instIds = $invoice->installments->pluck('id');
        $history = Allocation::where('company_id', $company->id)->where(fn ($w) => $w->whereIn('installment_id', $instIds)->orWhereIn('credit_installment_id', $instIds))
            ->with('movement', 'installment.invoice', 'creditInstallment.invoice')->orderBy('allocation_date')->orderBy('id')->get()
            ->map(fn ($a) => [...$q->allocation($a, $instIds->contains($a->credit_installment_id) ? $a->credit_installment_id : $a->installment_id), 'status' => $a->status,
                'movement_status' => $a->movement?->status, 'reversal_reason' => $a->reversal_reason ?? $a->movement?->reversal_reason, 'seq' => $a->installment->seq])->all();
        $fiscal = FiscalEntry::where('invoice_id', $invoice->id)->with('account')->orderBy('event_date')->orderBy('id')->get();
        $years = $fiscal->groupBy('year')->map(fn ($g, $y) => ['year' => (int) $y, 'amount' => Decimal::fromMinor($g->sum(fn ($e) => Decimal::toMinor((string) $e->amount))),
            'fiscal' => Decimal::fromMinor($g->sum(fn ($e) => Decimal::toMinor((string) $e->fiscal_amount))), 'check' => $g->contains('basis', 'da_verificare'),
            'entries' => $g->map(fn ($e) => ['date' => $e->event_date->toDateString(), 'account' => $e->account ? $e->account->code.' '.$e->account->name : null, 'basis' => $e->basis,
                'amount' => (string) $e->amount, 'fiscal_amount' => (string) $e->fiscal_amount, 'reason' => $e->reason])->values()->all()])->values()->all();
        $credits = [];
        if ($invoice->direction && $invoice->doc_type !== 'TD04') {
            $key = ScheduleService::partyKey($invoice);
            foreach ($company->invoices()->where('doc_type', 'TD04')->where('direction', $invoice->direction)->with('installments')->get() as $nc) {
                if (ScheduleService::partyKey($nc) !== $key) {
                    continue;
                }
                foreach ($nc->installments as $ni) {
                    $r = Ledger::installmentState($ni)['residual'];
                    if ($r > 0) {
                        $credits[] = ['installment_id' => $ni->id, 'number' => $nc->number, 'doc_date' => $nc->doc_date->toDateString(), 'residual' => Decimal::fromMinor($r)];
                    }
                }
            }
        }

        return [
            'sale' => $sale, 'direction' => $invoice->direction?->value, 'credit_note' => $invoice->doc_type === 'TD04', 'party' => $invoice->direction ? ScheduleService::partyName($invoice) : null,
            'ritenuta' => (string) $invoice->ritenuta, 'expected' => Decimal::fromMinor(ScheduleService::expectedPayable($invoice)), 'condition' => $invoice->payment_condition,
            'state' => ['amount' => Decimal::fromMinor($state['amount']), 'settled' => Decimal::fromMinor($state['settled']), 'residual' => Decimal::fromMinor($state['residual']),
                'paid' => $state['paid'], 'paid_label' => Ledger::paidLabel($state['paid'], $sale), 'due' => $state['due'], 'due_label' => $state['due'] ? Ledger::DUE_LABELS[$state['due']] : null,
                'check' => $state['check'], 'amount_check' => $state['amount_check']],
            'rates' => $rates, 'history' => $history, 'fiscal' => $years, 'credits' => $credits,
            'mode' => $invoice->doc_date ? FiscalService::modeFor($company, (int) $invoice->doc_date->format('Y')) : null,
            'modes' => self::PAYMENT_MODES, 'today' => now()->toDateString(), 'professionista' => $company->tipologia === CompanyType::Professionista,
        ];
    }

    /** Scadenze fiscali: versamenti IVA delle liquidazioni confermate (aperti finché la delega non è presentata) e termini IVA TR. */
    public function fiscalDeadlines(Request $request, Company $company): Response
    {
        $items = $this->query->fiscalDeadlines($company);

        $year = ActiveAccountingYear::current($request, $company);
        $per = $company->activeVatProfile($year)?->periodicity->value;

        return Inertia::render('Scadenziario/Deadlines', ['items' => $items, 'today' => now()->toDateString(), 'year' => $year, 'periodicity' => $per,
            'calendar' => FiscalCalendar::forYear($year, $per), 'types' => FiscalCalendar::TYPES]);
    }
}
