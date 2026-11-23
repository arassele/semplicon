<?php

namespace App\Http\Controllers;

use App\Domain\AccountRuleService;
use App\Domain\AccountService;
use App\Domain\Import\FatturaPaSchema;
use App\Domain\InvoiceService;
use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\RegistrationService;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\VatRegistration;
use App\Support\Decimal;
use App\Support\VatPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, Company $company, ?string $direction = null): Response
    {
        $dir = $direction ? InvoiceDirection::from($direction) : null;
        $years = self::years($company);
        $annoRaw = (string) $request->query('anno', '');
        $anno = $annoRaw === 'tutti' ? 'tutti' : (preg_match('/^\d{4}$/', $annoRaw) ? (int) $annoRaw : self::activeYear($company, $years));
        $sorts = ['data' => 'doc_date', 'numero' => 'number', 'controparte' => $dir === InvoiceDirection::Vendita ? 'customer_name' : 'supplier_name', 'imponibile' => 'taxable_total'];
        $filters = [
            'stato' => in_array($request->query('stato'), ['aperte', 'importata', 'da_verificare', 'registrata', 'scartata', 'archiviata'], true) ? $request->query('stato') : null,
            'anno' => $anno,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 80),
            'ordina' => array_key_exists((string) $request->query('ordina'), $sorts) ? $request->query('ordina') : 'data',
            'verso' => $request->query('verso') === 'asc' ? 'asc' : 'desc',
        ];

        $query = self::filtered($company, $dir, $filters);
        $page = $query->with('document:id,kind')->orderBy($sorts[$filters['ordina']], $filters['verso'])->orderByDesc('id')->paginate(50)->withQueryString();
        // Somme sulla stessa selezione lato server (sezione 3).
        $tot = self::filtered($company, $dir, $filters)->selectRaw('COUNT(*) as n, COALESCE(SUM(taxable_total),0) as t, COALESCE(SUM(tax_total),0) as v')->first();
        $year = fn ($q) => $anno === 'tutti' ? $q : self::yearScope($q, $anno);

        return Inertia::render('Invoices/Index', [
            'direction' => $dir?->value,
            'filters' => $filters,
            'years' => $years,
            'invoices' => $page->through(fn (Invoice $i) => self::row($i)),
            'totals' => ['count' => (int) $tot->n, 'taxable' => Decimal::normalize((string) $tot->t), 'tax' => Decimal::normalize((string) $tot->v)],
            'counts' => [
                'acquisto' => $year($company->invoices()->where('direction', 'acquisto')->where('status', '!=', 'archiviata'))->count(),
                'vendita' => $year($company->invoices()->where('direction', 'vendita'))->count(),
                'archiviata' => $year($company->invoices()->where('status', 'archiviata')->when($dir, fn ($q) => $q->where('direction', $dir)))->count(),
                'da_verificare' => $year($company->invoices()->where('status', InvoiceStatus::DaVerificare)->when($dir, fn ($q) => $q->where('direction', $dir)))->count(),
                'aperte' => $year($company->invoices()->whereIn('status', ['importata', 'da_verificare'])->when($dir, fn ($q) => $q->where('direction', $dir)))->count(),
                'registrata' => $year($company->invoices()->where('status', 'registrata')->when($dir, fn ($q) => $q->where('direction', $dir)))->count(),
            ],
        ]);
    }

    /** Selezione dell'elenco: acquisti per data di ricezione (altrimenti data del documento), vendite per data della fattura. */
    private static function filtered(Company $company, ?InvoiceDirection $dir, array $filters)
    {
        return $company->invoices()
            ->when($dir, fn ($q) => $q->where('direction', $dir))
            ->when($filters['stato'], fn ($q, $s) => $s === 'aperte' ? $q->whereIn('status', ['importata', 'da_verificare']) : $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'archiviata'))
            ->when($filters['anno'] !== 'tutti', fn ($q) => self::yearScope($q, (int) $filters['anno']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($filters['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(supplier_name) LIKE ?', [$like])->orWhereRaw('LOWER(customer_name) LIKE ?', [$like]));
            });
    }

    public static function yearScope($q, int $y)
    {
        $from = "{$y}-01-01";
        $to = "{$y}-12-31";

        return $q->where(fn ($w) => $w->where(fn ($a) => $a->where('direction', 'acquisto')->whereRaw('COALESCE(receipt_date, doc_date) BETWEEN ? AND ?', [$from, $to]))
            ->orWhere(fn ($v) => $v->where(fn ($n) => $n->where('direction', '!=', 'acquisto')->orWhereNull('direction'))->whereBetween('doc_date', [$from, $to])));
    }

    /** Anni disponibili: documenti e regimi creati; l'anno corrente in aggiunta, finché non è stato eliminato. */
    public static function years(Company $company): array
    {
        $y = fn ($d) => (int) substr((string) $d, 0, 4);
        $a = $company->invoices()->where(fn ($q) => $q->where('direction', '!=', 'acquisto')->orWhereNull('direction')->orWhereNull('receipt_date'))->distinct()->pluck('doc_date')->map($y);
        $b = $company->invoices()->where('direction', 'acquisto')->whereNotNull('receipt_date')->distinct()->pluck('receipt_date')->map($y);
        $years = $a->merge($b)->merge($company->vatProfiles()->pluck('year'))
            // Cespiti: gli anni con ammortamenti o movimenti (anche da progressivi importati) sono selezionabili
            ->merge(\App\Models\AssetDepreciation::where('company_id', $company->id)->where('status', '!=', 'stornato')->distinct()->pluck('year'))
            ->merge(\App\Models\AssetMovement::where('company_id', $company->id)->distinct()->pluck('year'));
        if ($company->currentYearSelectable()) {
            $years->push((int) now()->year);
        }

        return $years->unique()->sortDesc()->values()->all();
    }

    /** Anno contabile attivo: anno corrente, se esiste un regime, altrimenti l'anno più recente con regime. */
    public static function activeYear(Company $company, ?array $years = null): int
    {
        $now = (int) now()->year;
        $profiles = $company->vatProfiles()->pluck('year')->all();

        return in_array($now, $profiles, true) || ! $profiles ? $now : max(array_filter($profiles, fn ($y) => $y <= $now) ?: $profiles);
    }

    public function show(Request $request, Company $company, Invoice $invoice, RegistrationService $service): Response
    {
        $invoice->load('vatSummaries', 'lines', 'document', 'registrations.lines.account', 'registrations.creator', 'registrations.document');

        $back = (string) $request->query('ritorno', '');

        return Inertia::render('Invoices/Show', [
            'verify' => $request->boolean('verifica'),
            'returnTo' => str_starts_with($back, "/aziende/{$company->id}/") && ! str_contains($back, '//') ? $back : null,
            'viewOnly' => $request->boolean('vista'),
            'openCount' => $company->invoices()->whereIn('status', ['importata', 'da_verificare'])->count(),
            'registration' => $this->registrationPanel($company, $invoice, $service),
            'schedule' => ScadenziarioController::panel($company, $invoice),
            'history' => $invoice->registrations->map(fn (VatRegistration $r) => [
                'id' => $r->id, 'protocol' => $r->protocol, 'year' => $r->year, 'period' => $r->period, 'status' => $r->status, 'replaces' => $r->replaces_id ? VatRegistration::whereKey($r->replaces_id)->get(['protocol', 'year'])->map(fn ($o) => "{$o->protocol}/{$o->year}")->first() : null,
                'period_label' => VatPeriod::label($r->year, $r->period, $r->periodicity),
                'direction' => $r->direction->value, 'registration_date' => $r->registration_date->toDateString(),
                'receipt_date' => $r->receipt_date?->toDateString(), 'sign' => $r->sign,
                'taxable_total' => $r->taxable_total, 'tax_total' => $r->tax_total, 'deductible_total' => $r->deductible_total,
                'notes' => $r->notes, 'acknowledged' => $r->acknowledged ?? [], 'created_by' => $r->creator?->name, 'created_at' => $r->created_at->toIso8601String(),
                'document' => $r->document ? ['id' => $r->document->id, 'name' => $r->document->original_name, 'kind' => $r->document->kind] : null,
                'lines' => $r->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'summary_index' => $l->summary_index, 'account' => $l->account->code.' · '.$l->account->name, 'rate' => $l->rate, 'natura' => $l->natura,
                    'taxable' => $l->taxable, 'tax' => $l->tax, 'deductible_pct' => $l->deductible_pct, 'deductible_tax' => $l->deductible_tax,
                ]),
            ]),
            'credit' => $invoice->source === 'emessa' ? [
                'can' => app(InvoiceIssuer::class)->canCredit($invoice),
                'notes' => array_map(fn ($n) => ['id' => $n->id, 'number' => $n->number, 'doc_date' => $n->doc_date->toDateString(), 'total' => (string) $n->total_amount], app(InvoiceIssuer::class)->creditNotesFor($invoice)),
            ] : null,
            'discard_reason' => $invoice->discard_reason,
            'invoice' => [
                ...self::row($invoice),
                'format' => $invoice->format,
                'body_index' => $invoice->body_index,
                'supplier' => ['name' => $invoice->supplier_name, 'vat' => $invoice->supplier_vat, 'cf' => $invoice->supplier_cf],
                'customer' => ['name' => $invoice->customer_name, 'vat' => $invoice->customer_vat, 'cf' => $invoice->customer_cf],
                'total_amount' => $invoice->total_amount,
                'issues' => $invoice->issues ?? [],
                'conflicts_with' => $invoice->conflicts_with,
                'document' => $invoice->document ? ['id' => $invoice->document->id, 'name' => $invoice->document->original_name, 'kind' => $invoice->document->kind] : null,
                'description' => $invoice->description, 'archived_at' => $invoice->archived_at?->toIso8601String(), 'receipt_date' => $invoice->receipt_date?->toDateString(),
                'summaries' => $invoice->vatSummaries->map(fn ($s) => [
                    'rate' => $s->rate, 'natura' => $s->natura, 'natura_label' => FatturaPaSchema::label('NaturaType', $s->natura),
                    'taxable' => $s->taxable, 'tax' => $s->tax, 'esigibilita_label' => FatturaPaSchema::label('EsigibilitaIVAType', $s->esigibilita),
                ]),
                'lines' => $invoice->lines->map(fn ($l) => $l->only(['line_no', 'description', 'quantity', 'unit', 'unit_price', 'total', 'rate', 'natura'])),
            ],
        ]);
    }

    /** Dati per il modulo «Verifica e registra»: avvisi, suggerimenti, conti, periodi. */
    private function registrationPanel(Company $company, Invoice $invoice, RegistrationService $service): ?array
    {
        // Anche per le fatture registrate: il modulo serve poi a modificare la registrazione.
        if (! $invoice->status->isOpen() && ! $invoice->activeRegistration()) {
            return null;
        }
        app(AccountService::class)->ensureDefaults($company);
        ['blockers' => $blockers, 'acknowledge' => $ack] = $service->checks($company, $invoice);
        $blockers = array_values(array_filter($blockers, fn ($b) => $b !== 'La fattura non è da registrare.'));
        $direction = $invoice->direction;

        // Anni con regime confermato: periodicità per anno per la scelta del periodo.
        $years = $company->vatProfiles()->where('status', 'confermato')->orderBy('year')->get(['year', 'periodicity'])
            ->mapWithKeys(fn ($p) => [$p->year => $p->periodicity->value])->all();
        $docDate = $invoice->doc_date->toDateString();
        $suggestSale = $service->suggestedPeriod($company, $docDate);
        $suggestPurchase = $service->suggestedPeriod($company, $docDate); // la data di ricezione viene proposta con la data della fattura
        $docYear = (int) substr($docDate, 0, 4);
        if (! isset($years[$docYear])) {
            $blockers[] = "Nessun regime IVA confermato per il {$docYear}: confermalo in Impostazioni › Regime IVA prima di registrare.";
        }

        $suggest = fn ($dir) => $dir ? $service->suggestedAccount($company, $invoice, $dir) : null;

        return [
            'blockers' => array_values(array_unique($blockers)),
            'acknowledge' => $ack,
            'direction' => $direction?->value,
            'today' => now()->toDateString(),
            'years' => $years,
            'suggest' => [
                'vendita' => $suggestSale, 'acquisto' => $suggestPurchase,
                'account_acquisto' => $suggest(InvoiceDirection::Acquisto), 'account_vendita' => $suggest(InvoiceDirection::Vendita),
            ],
            'accounts' => $company->accounts()->where('active', true)->where('iva_rilevante', true)->get(['id', 'code', 'name', 'type', 'categoria', 'voce_cee', 'iva_detraibile_pct', 'iva_nota', 'deducibilita_nota', 'limite']),
            'is_credit_note' => $invoice->doc_type === 'TD04',
            // Ticket 09: suggerimenti di regola per gruppo IVA e offerta di memorizzare una scelta come regola.
            'rule_suggestions' => (object) app(AccountRuleService::class)->suggestions($company, $invoice),
            'rule_party' => AccountRuleService::partyKey($invoice) && AccountRuleService::scope($invoice)
                ? ($invoice->direction === InvoiceDirection::Vendita ? $invoice->customer_name : $invoice->supplier_name) : null,
            'group_descriptions' => $invoice->vatSummaries->values()->map(fn ($s) => $invoice->lines
                ->filter(fn ($l) => $l->natura === $s->natura && (string) $l->rate === (string) $s->rate)->pluck('description')->values()->all())->all(),
        ];
    }

    /** Eliminare la fattura con le registrazioni (indicazione del committente, 19.11.2026). */
    public function destroy(Request $request, Company $company, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $list = match ($invoice->direction?->value) {
            'acquisto' => 'acquisti', 'vendita' => 'vendite', default => 'fatture'
        };
        $number = $invoice->number;
        if ($invoice->direction?->value === 'acquisto') {
            // Sezione 1: le fatture di acquisto non registrate vengono archiviate, quelle registrate solo stornate.
            $service->archive($company, $invoice, $request->user());

            return redirect("/aziende/{$company->id}/{$list}?stato=aperte")->with('success', "Fattura {$number} eliminata dalla lista e archiviata con originale e allegati. Per riportarla in lista: Acquisti › Archiviate › Ripristina.");
        }
        $service->delete($company, $invoice, $request->user());

        return redirect("/aziende/{$company->id}/{$list}")->with('success', "Fattura {$number} eliminata.");
    }

    public function restoreArchived(Request $request, Company $company, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $service->restore($company, $invoice, $request->user());

        return redirect("/aziende/{$company->id}/fatture/{$invoice->id}")->with('success', "Fattura {$invoice->number} ripristinata: è di nuovo da registrare.");
    }

    public static function row(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'direction' => $i->direction?->value,
            'direction_label' => $i->direction?->label() ?? 'Da definire',
            'doc_type' => $i->doc_type,
            'doc_type_label' => FatturaPaSchema::label('TipoDocumentoType', $i->doc_type) ?? $i->doc_type,
            'number' => $i->number,
            'doc_date' => $i->doc_date->toDateString(),
            'receipt_date' => $i->receipt_date?->toDateString(),
            'year' => (int) (($i->direction?->value === 'acquisto' && $i->receipt_date ? $i->receipt_date : $i->doc_date)->format('Y')),
            'counterparty' => $i->direction === null ? $i->supplier_name : $i->counterpartyName(),
            'currency' => $i->currency,
            'taxable_total' => $i->taxable_total,
            'tax_total' => $i->tax_total,
            'status' => $i->status->value,
            'status_label' => $i->status->label(),
            'source' => $i->source,
            'has_xml' => $i->source === 'emessa' || $i->document?->kind === 'xml',
            'deletable' => $i->source !== 'emessa' && $i->status->value !== 'registrata',
        ];
    }
}
