<?php

namespace App\Http\Controllers;

use App\Domain\Import\Reprocessor;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReprocessing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Sezione 2: confronto e recepimento di una rielaborazione. */
class ReprocessingController extends Controller
{
    public function show(Company $company, InvoiceReprocessing $invoiceReprocessing): Response
    {
        $r = $invoiceReprocessing->load('invoice', 'document');
        $inv = $r->invoice;
        $reg = $inv?->activeRegistration();

        return Inertia::render('Invoices/Reprocess', [
            'r' => ['id' => $r->id, 'status' => $r->status, 'message' => $r->message, 'diff' => $r->diff, 'impacts' => $r->impacts ?? [], 'candidates' => $r->candidates ?? [],
                'booking_relevant' => $r->booking_relevant, 'document' => $r->document?->original_name, 'number' => $r->payload['body']['number'], 'doc_date' => $r->payload['body']['date']],
            'invoice' => $inv ? ['id' => $inv->id, 'number' => $inv->number, 'status' => $inv->status->value, 'status_label' => $inv->status->label(), 'registered' => (bool) $reg,
                'protocol' => $reg ? "{$reg->protocol}/{$reg->year}" : null] : null,
            'locked' => $reg && $r->booking_relevant ? Reprocessor::lockedPeriod($company, $reg) : null,
        ]);
    }

    public function apply(Request $request, Company $company, InvoiceReprocessing $invoiceReprocessing, Reprocessor $svc): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $inv = $svc->apply($company, $invoiceReprocessing, $request->user(), $request->boolean('storno'));

        return redirect("/aziende/{$company->id}/fatture/{$inv->id}")->with('success', $request->boolean('storno')
            ? "Fattura {$inv->number} aggiornata: la registrazione è stornata oggi, la fattura è di nuovo da registrare."
            : "Fattura {$inv->number} aggiornata con i dati della nuova elaborazione (versione precedente conservata).");
    }

    public function assign(Request $request, Company $company, InvoiceReprocessing $invoiceReprocessing, Reprocessor $svc): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $data = $request->validate(['invoice_id' => ['required']]);
        $next = $svc->assign($company, $invoiceReprocessing, $request->user(), $data['invoice_id'] === 'nuovo' ? null : (int) $data['invoice_id']);

        return $next ? redirect("/aziende/{$company->id}/rielaborazioni/{$next->id}")->with('success', 'Documento abbinato: verifica le differenze.')
            : redirect("/aziende/{$company->id}/fatture")->with('success', 'Importato come nuovo documento.');
    }

    public function discard(Request $request, Company $company, InvoiceReprocessing $invoiceReprocessing, Reprocessor $svc): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $svc->discard($company, $invoiceReprocessing, $request->user());

        return back()->with('success', 'Nuova elaborazione scartata: i dati della fattura restano invariati.');
    }

    public function fromInvoice(Request $request, Company $company, Invoice $invoice, Reprocessor $svc): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $res = $svc->fromInvoice($company, $invoice, $request->user());

        return match ($res['status']) {
            'modificato', 'da_abbinare' => redirect("/aziende/{$company->id}/rielaborazioni/{$res['reprocessing_id']}"),
            'archiviata' => back()->with('success', 'Fattura archiviata: ripristinala prima di rielaborare l’XML.'),
            default => back()->with('success', 'Documento invariato: la nuova elaborazione dell’XML non cambia i dati.'),
        };
    }
}
