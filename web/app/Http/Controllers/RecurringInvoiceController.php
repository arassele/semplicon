<?php

namespace App\Http\Controllers;

use App\Domain\Issuing\RecurringInvoiceService;
use App\Models\Company;
use App\Models\RecurringInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Fatture ricorrenti: creare modelli (dal modulo di emissione), emettere quelle in scadenza, sospendere, eliminare. */
class RecurringInvoiceController extends Controller
{
    public function __construct(private RecurringInvoiceService $svc) {}

    public function index(Company $company): Response
    {
        $today = now()->toDateString();

        return Inertia::render('Invoices/Recurring', [
            'today' => $today,
            'items' => $company->recurringInvoices()->with('lastInvoice:id,number')->orderByDesc('active')->orderBy('next_date')->get()->map(fn (RecurringInvoice $r) => [
                'id' => $r->id, 'name' => $r->name, 'frequency' => $r->frequency, 'next_date' => $r->next_date->toDateString(), 'end_date' => $r->end_date?->toDateString(), 'active' => $r->active,
                'auto_issue' => $r->auto_issue, 'due' => $this->svc->isDue($r, $today), 'runs' => $r->runs, 'last_run_at' => $r->last_run_at?->toIso8601String(), 'last_invoice' => $r->lastInvoice ? ['id' => $r->lastInvoice->id, 'number' => $r->lastInvoice->number] : null,
                'last_error' => $r->last_error, 'customer' => $r->payload['customer']['name'] ?? trim(($r->payload['customer']['first_name'] ?? '').' '.($r->payload['customer']['last_name'] ?? '')),
                'lines' => count((array) ($r->payload['lines'] ?? [])), 'ended' => $r->end_date && $r->next_date->toDateString() > $r->end_date->toDateString(),
            ])->values(),
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $d = $request->validate(['recurring' => ['required', 'array'], 'recurring.name' => ['nullable', 'string', 'max:80'], 'recurring.frequency' => ['required', 'string'],
            'recurring.start_date' => ['required', 'date_format:Y-m-d'], 'recurring.end_date' => ['nullable', 'date_format:Y-m-d'], 'recurring.auto_issue' => ['boolean'],
            'recurring.payment_days' => ['nullable', 'integer', 'min:0', 'max:365']]);
        $this->svc->create($company, $request->user(), [...$d['recurring'], 'payload' => $request->except(['recurring', '_token'])]);

        return redirect("/aziende/{$company->id}/fatture/ricorrenti")->with('success', 'Fattura ricorrente salvata.');
    }

    public function issue(Request $request, Company $company, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($recurringInvoice->company_id === $company->id, 404);
        $invoice = $this->svc->issueNow($company, $request->user(), $recurringInvoice);

        return redirect("/aziende/{$company->id}/fatture/{$invoice->id}")->with('success', "Fattura {$invoice->number} emessa dalla fattura ricorrente «{$recurringInvoice->name}». Scarica l’XML e trasmettilo al SdI.");
    }

    public function toggle(Request $request, Company $company, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($recurringInvoice->company_id === $company->id, 404);
        $this->svc->setActive($company, $request->user(), $recurringInvoice, ! $recurringInvoice->active);

        return back()->with('success', $recurringInvoice->active ? 'Fattura ricorrente riattivata.' : 'Fattura ricorrente sospesa.');
    }

    public function destroy(Request $request, Company $company, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        Gate::authorize('manage', $company);
        abort_unless($recurringInvoice->company_id === $company->id, 404);
        $this->svc->delete($company, $request->user(), $recurringInvoice);

        return back()->with('success', 'Fattura ricorrente eliminata. Le fatture già emesse restano.');
    }
}
