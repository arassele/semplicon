<?php

namespace App\Http\Controllers;

use App\Domain\Esterometro\IntegrationService;
use App\Domain\RegistrationService;
use App\Enums\InvoiceDirection;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceIntegration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Sezione 5: «Integra IVA / Reverse charge» con anteprima per gruppo IVA. */
class IntegrationController extends Controller
{
    public function create(Company $company, Invoice $invoice, IntegrationService $svc, RegistrationService $reg): Response
    {
        $invoice->load('vatSummaries');
        $p = $svc->propose($company, $invoice);
        $active = $invoice->activeRegistration()?->load('lines');
        $receipt = ($active?->receipt_date ?? $invoice->receipt_date ?? $invoice->doc_date)->toDateString();
        $years = $company->vatProfiles()->where('status', 'confermato')->orderBy('year')->get(['year', 'periodicity'])
            ->mapWithKeys(fn ($pr) => [$pr->year => $pr->periodicity->value])->all();
        $acc = $reg->suggestedAccount($company, $invoice, InvoiceDirection::Acquisto);
        $existing = InvoiceIntegration::where('invoice_id', $invoice->id)->orderByDesc('id')->get()
            ->map(fn ($i) => ['id' => $i->id, 'doc_type' => $i->doc_type, 'number' => "{$i->number}/{$i->year}", 'doc_date' => $i->doc_date->toDateString(), 'groups' => $i->groups,
                'active' => $active?->integration_id === $i->id, 'linked_document' => (bool) $i->document_id]);

        return Inertia::render('Invoices/Integrate', [
            'invoice' => ['id' => $invoice->id, 'number' => $invoice->number, 'doc_date' => $invoice->doc_date->toDateString(), 'supplier' => $invoice->supplier_name,
                'supplier_vat' => $invoice->supplier_vat, 'currency' => $invoice->currency, 'total' => (string) $invoice->total_amount, 'doc_type' => $invoice->doc_type,
                'direction' => $invoice->direction?->value, 'status' => $invoice->status->value, 'registered' => (bool) $active,
                'protocol' => $active ? "{$active->protocol}/{$active->year}" : null],
            'proposal' => $p,
            'groups' => $invoice->vatSummaries->values()->map(function ($s, $k) use ($active, $acc, $p) {
                $line = $active?->lines->firstWhere('summary_index', $k);
                $prop = collect($p['groups'])->firstWhere('summary_index', $k);

                return ['summary_index' => $k, 'rate' => (string) $s->rate, 'natura' => $s->natura, 'taxable' => (string) $s->taxable, 'tax' => (string) $s->tax,
                    'candidate' => (bool) $prop, 'account_id' => $line?->account_id ?? $acc, 'deductible_pct' => $prop['deductible_pct'] ?? ($line ? (string) (float) $line->deductible_pct : '100')];
            })->all(),
            'types' => IntegrationService::TYPES,
            'kinds' => IntegrationService::KINDS,
            'years' => $years,
            'receipt_date' => $receipt,
            'suggest' => $reg->suggestedPeriod($company, $receipt),
            'today' => now()->toDateString(),
            'accounts' => $company->accounts()->where('active', true)->where('iva_rilevante', true)->where('type', 'costo')->orderBy('code')->get(['id', 'code', 'name']),
            'existing' => $existing,
        ]);
    }

    public function store(Request $request, Company $company, Invoice $invoice, IntegrationService $svc): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate([
            'doc_type' => ['required', 'string'], 'doc_date' => ['required', 'date_format:Y-m-d'], 'kind' => ['nullable', 'string'], 'reason' => ['nullable', 'string', 'max:500'],
            'receipt_date' => ['required', 'date_format:Y-m-d'], 'registration_date' => ['required', 'date_format:Y-m-d'],
            'year' => ['required', 'integer'], 'period' => ['required', 'integer'],
            'groups' => ['required', 'array'], 'groups.*.summary_index' => ['required', 'integer'], 'groups.*.integrate' => ['boolean'],
            'groups.*.rate' => ['nullable', 'string'], 'groups.*.account_id' => ['required', 'integer'], 'groups.*.deductible_pct' => ['required', 'string'],
        ]);
        $int = $svc->integrate($company, $invoice, $request->user(), $data);

        return redirect("/aziende/{$company->id}/fatture/{$invoice->id}")->with('success',
            "Integrazione {$int->doc_type} n. {$int->number}/{$int->year} registrata: IVA a debito nel registro vendite, IVA detraibile nel registro acquisti. Il debito verso il fornitore resta sulla fattura originale.");
    }
}
