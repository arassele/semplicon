<?php

namespace App\Http\Controllers;

use App\Domain\Import\FatturaPaSchema;
use App\Domain\ManualInvoiceService;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Fatture manuali: registrazione senza importazione, allegato facoltativo. */
class ManualInvoiceController extends Controller
{
    public function create(Request $request, Company $company): Response
    {
        return $this->form(in_array($request->query('direzione'), ['acquisto', 'vendita'], true) ? $request->query('direzione') : 'acquisto', null);
    }

    public function edit(Company $company, Invoice $invoice): Response
    {
        abort_unless($invoice->source === 'manuale', 404);
        $invoice->load('vatSummaries', 'lines', 'document');
        $cp = $invoice->direction?->value === 'vendita' ? ['customer_name', 'customer_vat', 'customer_cf'] : ['supplier_name', 'supplier_vat', 'supplier_cf'];
        $reg = $invoice->activeRegistration();

        return $this->form($invoice->direction?->value ?? 'acquisto', [
            'id' => $invoice->id, 'doc_type' => $invoice->doc_type, 'number' => $invoice->number, 'doc_date' => $invoice->doc_date->toDateString(), 'receipt_date' => $invoice->receipt_date?->toDateString(),
            'counterparty_name' => $invoice->{$cp[0]}, 'counterparty_vat' => $invoice->{$cp[1]}, 'counterparty_cf' => $invoice->{$cp[2]}, 'description' => $invoice->description,
            // Righe (sezione 4/6); documenti vecchi solo con riepilogo.
            'lines' => $invoice->lines->isNotEmpty()
                ? $invoice->lines->sortBy('line_no')->map(fn ($l) => ['description' => $l->description, 'rate' => str_replace('.', ',', rtrim(rtrim((string) $l->rate, '0'), '.')), 'natura' => $l->natura ?? '',
                    'taxable' => str_replace('.', ',', Decimal::normalize(preg_replace('/(\.\d{2})\d*$/', '$1', (string) ($l->original_total ?? $l->total)))), 'tax' => ''])->values()
                : $invoice->vatSummaries->map(fn ($s) => ['description' => '', 'rate' => str_replace('.', ',', rtrim(rtrim((string) $s->rate, '0'), '.')), 'natura' => $s->natura ?? '', 'taxable' => str_replace('.', ',', (string) $s->taxable), 'tax' => ''])->values(),
            'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate ? rtrim(rtrim(str_replace('.', ',', (string) $invoice->exchange_rate), '0'), ',') : '',
            'counterparty_country' => $invoice->direction?->value === 'vendita' ? $invoice->customer_country : $invoice->supplier_country,
            'address' => ($invoice->direction?->value === 'vendita' ? $invoice->customer_address : $invoice->supplier_address) ?? [],
            'document' => $invoice->document ? ['id' => $invoice->document->id, 'name' => $invoice->document->original_name] : null,
            'registered' => $reg ? "{$reg->protocol}/{$reg->year}" : null,
        ]);
    }

    private function form(string $direction, ?array $invoice): Response
    {
        $codes = FatturaPaSchema::codes();

        return Inertia::render('Invoices/Create', [
            'invoice' => $invoice,
            'direction' => $direction,
            'docTypes' => collect($codes['TipoDocumentoType'] ?? [])->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'nature' => collect($codes['NaturaType'] ?? [])->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'today' => now()->toDateString(),
            'accept' => '.'.implode(',.', ManualInvoiceService::ATTACHMENT_TYPES),
            'maxMb' => intdiv(ManualInvoiceService::MAX_KB, 1024),
        ]);
    }

    public function store(Request $request, Company $company, ManualInvoiceService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $invoice = $service->create($company, $request->user(), $this->validated($request), $request->file('file'));

        return redirect("/aziende/{$company->id}/fatture/{$invoice->id}")->with('success', 'Fattura manuale creata: ora verificala e registrala.');
    }

    public function update(Request $request, Company $company, Invoice $invoice, ManualInvoiceService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $this->validated($request);
        $registered = (bool) $invoice->activeRegistration();
        $service->update($company, $invoice, $request->user(), $data, $request->file('file'), $request->boolean('remove_attachment'));

        return redirect("/aziende/{$company->id}/fatture/{$invoice->id}")->with('success', $registered ? 'Fattura modificata e registrazione aggiornata.' : 'Fattura modificata.');
    }

    private function validated(Request $request): array
    {
        $amount = ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'];
        $data = $request->validate([
            'direction' => ['required', 'in:acquisto,vendita'],
            'doc_type' => ['required', 'string', 'size:4'],
            'number' => ['required', 'string', 'max:20'],
            'doc_date' => ['required', 'date_format:Y-m-d'],
            'receipt_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:doc_date', 'before_or_equal:today'],
            'counterparty_name' => ['required', 'string', 'max:200'],
            'counterparty_vat' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z]{0,2}\s*[0-9A-Za-z]{2,28}$/'],
            'counterparty_cf' => ['nullable', 'string', 'max:16', 'regex:/^[0-9A-Za-z]{11,16}$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'exchange_rate' => ['nullable', 'regex:/^\d{1,9}([.,]\d{1,6})?$/'],
            'counterparty_country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'counterparty_address' => ['nullable', 'string', 'max:60'],
            'counterparty_zip' => ['nullable', 'string', 'max:10'],
            'counterparty_city' => ['nullable', 'string', 'max:60'],
            'counterparty_province' => ['nullable', 'string', 'max:2'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.rate' => ['required', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'lines.*.natura' => ['nullable', 'string', 'max:5'],
            'lines.*.taxable' => $amount,
            'lines.*.tax' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'file' => ['nullable', 'file', 'max:'.ManualInvoiceService::MAX_KB, 'mimes:'.implode(',', ManualInvoiceService::ATTACHMENT_TYPES)],
        ], [
            'lines.*.taxable.regex' => 'Importo non valido (es. 1250,00); per le note di credito indica importi positivi.',
            'lines.*.tax.regex' => 'Importo non valido.', 'lines.*.rate.regex' => 'Aliquota non valida.', 'exchange_rate.regex' => 'Cambio non valido (es. 1,0850).',
            'counterparty_vat.regex' => 'Partita IVA non valida.', 'counterparty_cf.regex' => 'Codice fiscale non valido.',
            'file.mimes' => 'Allegato ammesso: PDF, JPG, PNG o XML.',
        ], ['number' => 'numero', 'doc_date' => 'data', 'receipt_date' => 'data di ricezione', 'counterparty_name' => 'controparte', 'file' => 'allegato']);

        return $data;
    }

    public function attach(Request $request, Company $company, Invoice $invoice, ManualInvoiceService $service): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $request->validate(['file' => ['required', 'file', 'max:'.ManualInvoiceService::MAX_KB, 'mimes:'.implode(',', ManualInvoiceService::ATTACHMENT_TYPES)]],
            ['file.mimes' => 'Allegato ammesso: PDF, JPG, PNG o XML.'], ['file' => 'allegato']);
        $service->attach($company, $invoice, $request->user(), $request->file('file'));

        return back()->with('success', 'Documento allegato.');
    }
}
