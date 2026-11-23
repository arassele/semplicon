<?php

namespace App\Http\Controllers;

use App\Domain\CatalogService;
use App\Domain\Esterometro\CrossBorderService;
use App\Domain\Import\FatturaPaSchema;
use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\Issuing\IssuingSettings;
use App\Domain\RegistrationService;
use App\Enums\InvoiceDirection;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\XmlExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Emissione fattura elettronica: modulo, emissione, download del file XML. */
class IssueInvoiceController extends Controller
{
    /** Nota di credito con un clic: modulo precompilato con righe e cliente della fattura emessa. */
    public function creditNote(Company $company, Invoice $invoice, InvoiceIssuer $issuer, RegistrationService $reg, CatalogService $catalog): Response
    {
        Gate::authorize('manage', $company);

        return $this->create($company, $issuer, $reg, $catalog, $issuer->creditNoteDraft($invoice));
    }

    public function create(Company $company, InvoiceIssuer $issuer, RegistrationService $reg, CatalogService $catalog, ?array $prefill = null): Response
    {
        $today = now()->toDateString();
        $codes = FatturaPaSchema::codes();
        $last = $company->invoices()->where('source', 'emessa')->latest('id')->first();
        $banks = $company->bankAccounts()->orderByDesc('is_default')->get(['iban', 'bank_name']);
        // Clienti da vendite precedenti (suggerimenti da recepire)
        $customers = $company->invoices()->where('direction', InvoiceDirection::Vendita)->orderByDesc('id')->limit(200)->get()
            ->unique(fn ($i) => $i->customer_vat ?? $i->customer_cf ?? mb_strtolower((string) $i->customer_name))->take(60)->map(function ($i) {
                $saved = $i->issue_data['customer'] ?? null;
                $a = (array) ($i->customer_address ?? []);

                return $saved ?: ['kind' => 'azienda', 'name' => $i->customer_name, 'first_name' => '', 'last_name' => '', 'vat' => $i->customer_vat, 'cf' => $i->customer_cf, 'country' => $i->customer_country ?: 'IT',
                    'address' => $a['indirizzo'] ?? '', 'zip' => $a['cap'] ?? '', 'city' => $a['comune'] ?? '', 'province' => $a['provincia'] ?? '', 'sdi_code' => null, 'pec' => null, 'is_pa' => false];
            })->values();
        // Prima l'anagrafica (clienti), poi i clienti da fatture precedenti che vi mancano ancora
        $partners = $company->partners()->where('is_customer', true)->where('active', true)->orderByRaw('LOWER(COALESCE(name, last_name))')->limit(500)->get();
        $known = $partners->flatMap(fn ($p) => array_filter([$p->vat ? strtoupper($p->vat) : null, $p->cf ? strtoupper($p->cf) : null]))->all();
        $customers = $partners->map(fn ($p) => ['kind' => $p->kind, 'name' => (string) $p->name, 'first_name' => (string) $p->first_name, 'last_name' => (string) $p->last_name, 'vat' => $p->vat, 'cf' => $p->cf, 'country' => $p->country,
            'address' => (string) $p->address, 'zip' => (string) $p->zip, 'city' => (string) $p->city, 'province' => (string) $p->province, 'sdi_code' => $p->sdi_code, 'pec' => $p->pec, 'is_pa' => $p->is_pa,
            'payment_mode' => $p->payment_mode, 'payment_days' => $p->payment_days, 'master' => true])
            ->concat($customers->reject(fn ($c) => in_array(strtoupper((string) ($c['vat'] ?? '')), $known, true) || in_array(strtoupper((string) ($c['cf'] ?? '')), $known, true)))->values();
        $account = $company->accounts()->where('type', 'ricavo')->where('active', true)->where('iva_rilevante', true)->orderByDesc('is_default')->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('Invoices/Issue', [
            'companyIssues' => $issuer->companyIssues($company),
            'nextNumber' => $issuer->peekNumber($company, (int) substr($today, 0, 4)),
            'today' => $today,
            'docTypes' => InvoiceIssuer::DOC_TYPES,
            'paymentModes' => InvoiceIssuer::PAYMENT_MODES,
            'regimes' => InvoiceIssuer::REGIMES,
            'regime' => $last?->issue_data['regime'] ?? 'RF01',
            'nature' => collect($codes['NaturaType'] ?? [])->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'accounts' => $account,
            'account' => $account->first()?->id,
            'bank' => $banks->first(),
            'customers' => $customers,
            'bolloThreshold' => InvoiceIssuer::BOLLO_THRESHOLD,
            'settings' => IssuingSettings::for($company),
            'settingTypes' => collect(IssuingSettings::options())->only(['cassa_types', 'ritenuta_types'])->all(),
            'acconti' => $this->accontiFor($company, $issuer),
            'catalog' => $catalog->forForm($company),
            'prefill' => $prefill,
            'hasRegime' => (bool) $reg->suggestedPeriod($company, $today),
        ]);
    }

    /** Acconti emessi (TD02/TD03) non ancora detratti, con cliente e righe. */
    private function accontiFor(Company $company, InvoiceIssuer $issuer): array
    {
        $deducted = $issuer->deductedIds($company);

        return $company->invoices()->where('source', 'emessa')->whereIn('doc_type', ['TD02', 'TD03'])->whereNotIn('id', $deducted)->with('lines')->orderByDesc('id')->limit(100)->get()
            ->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'doc_date' => $i->doc_date->toDateString(), 'doc_type' => $i->doc_type, 'customer' => $i->customer_name,
                'customer_vat' => $i->customer_vat, 'customer_cf' => $i->customer_cf, 'taxable' => (string) $i->taxable_total])->values()->all();
    }

    /** Anteprima degli importi nel modulo: stesso calcolo dell'emissione, senza salvare. */
    public function quote(Request $request, Company $company, InvoiceIssuer $issuer): JsonResponse
    {
        return response()->json($issuer->quote($company, $request->all()));
    }

    public function store(Request $request, Company $company, InvoiceIssuer $issuer): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $d = $request->validate([
            'doc_type' => ['required', 'string'], 'doc_date' => ['required', 'date_format:Y-m-d'], 'regime' => ['required', 'string'], 'account_id' => ['required', 'integer'],
            'payment_mode' => ['required', 'string'], 'causale' => ['nullable', 'string', 'max:400'], 'bollo' => ['boolean'], 'split' => ['boolean'],
            'ref_number' => ['nullable', 'string', 'max:20'], 'ref_date' => ['nullable', 'date_format:Y-m-d'], 'ref_normativo' => ['nullable', 'string', 'max:100'],
            'customer' => ['required', 'array'], 'customer.kind' => ['nullable', 'in:azienda,persona'], 'customer.name' => ['nullable', 'string', 'max:80'],
            'customer.first_name' => ['nullable', 'string', 'max:60'], 'customer.last_name' => ['nullable', 'string', 'max:60'], 'customer.vat' => ['nullable', 'string', 'max:30'],
            'customer.cf' => ['nullable', 'string', 'max:16'], 'customer.country' => ['nullable', 'string', 'max:2'], 'customer.address' => ['nullable', 'string', 'max:60'],
            'customer.zip' => ['nullable', 'string', 'max:10'], 'customer.city' => ['nullable', 'string', 'max:60'], 'customer.province' => ['nullable', 'string', 'max:2'],
            'customer.sdi_code' => ['nullable', 'string', 'max:7'], 'customer.pec' => ['nullable', 'string', 'max:255'], 'customer.is_pa' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.description' => ['nullable', 'string', 'max:1000'], 'lines.*.quantity' => ['nullable', 'string', 'max:12'],
            'lines.*.unit' => ['nullable', 'string', 'max:10'], 'lines.*.price' => ['nullable', 'string', 'max:14'], 'lines.*.rate' => ['nullable', 'string', 'max:5'], 'lines.*.natura' => ['nullable', 'string', 'max:5'],
            'lines.*.discount' => ['nullable', 'string', 'max:6'], 'apply_ritenuta' => ['boolean'], 'apply_casse' => ['nullable', 'array', 'max:2'], 'apply_casse.*' => ['boolean'],
            'acconti' => ['nullable', 'array', 'max:20'], 'acconti.*' => ['integer'],
            'payments' => ['nullable', 'array', 'max:24'], 'payments.*.date' => ['nullable', 'string', 'max:10'], 'payments.*.amount' => ['nullable', 'string', 'max:14'],
        ]);
        $invoice = $issuer->issue($company, $request->user(), $d);

        return redirect("/aziende/{$company->id}/fatture/{$invoice->id}")->with('success',
            "Fattura {$invoice->number} emessa e registrata. Scarica il file XML e trasmettilo al SdI (il file è già controllato rispetto allo schema ufficiale).");
    }

    /** Scaricare la FatturaPA generata della fattura emessa. */
    public function download(Request $request, Company $company, Invoice $invoice, CrossBorderService $svc)
    {
        $export = XmlExport::where('company_id', $company->id)->where('invoice_id', $invoice->id)->orderByDesc('version')->firstOrFail();
        abort_unless($invoice->source === 'emessa', 404);
        $svc->downloaded($company, $export, $request->user());

        return response($export->content, 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="'.$export->filename.'"']);
    }
}
