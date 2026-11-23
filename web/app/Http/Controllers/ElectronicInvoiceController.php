<?php

namespace App\Http\Controllers;

use App\Domain\Issuing\TransmissionService;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\XmlExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Fatture elettroniche: panoramica delle fatture XML emesse, esportazione collettiva, stato «trasmessa» (trasmissione esterna). */
class ElectronicInvoiceController extends Controller
{
    public function __construct(private TransmissionService $svc) {}

    public function index(Request $request, Company $company): Response
    {
        $annoRaw = (string) $request->query('anno', '');
        $anno = $annoRaw === 'tutti' ? 'tutti' : (int) $annoRaw;
        $stato = in_array($request->query('stato'), ['da_trasmettere', 'trasmesse'], true) ? $request->query('stato') : null;
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)).'%';
        $base = $company->invoices()->where('source', 'emessa')->when($anno !== 'tutti', fn ($w) => $w->whereBetween('doc_date', ["{$anno}-01-01", "{$anno}-12-31"]));
        $rows = (clone $base)->when($stato === 'da_trasmettere', fn ($w) => $w->whereNull('transmitted_at'))->when($stato === 'trasmesse', fn ($w) => $w->whereNotNull('transmitted_at'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->whereRaw('LOWER(number) LIKE ?', [$like])->orWhereRaw('LOWER(customer_name) LIKE ?', [$like])->orWhereRaw('LOWER(customer_vat) LIKE ?', [$like])))
            ->orderByDesc('doc_date')->orderByDesc('id')->limit(500)->get();
        $files = XmlExport::where('company_id', $company->id)->whereIn('invoice_id', $rows->pluck('id'))->orderBy('version')->get()->keyBy('invoice_id');

        return Inertia::render('Invoices/Electronic', [
            'filters' => ['anno' => $anno, 'stato' => $stato, 'q' => $q], 'today' => now()->toDateString(), 'max' => TransmissionService::MAX_BATCH,
            'counts' => ['tutte' => (clone $base)->count(), 'da_trasmettere' => (clone $base)->whereNull('transmitted_at')->count(), 'trasmesse' => (clone $base)->whereNotNull('transmitted_at')->count()],
            'invoices' => $rows->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'doc_type' => $i->doc_type, 'doc_date' => $i->doc_date->toDateString(), 'customer' => $i->customer_name, 'total' => (string) $i->total_amount,
                'filename' => $files[$i->id]->filename ?? null, 'sha256' => isset($files[$i->id]) ? substr($files[$i->id]->sha256, 0, 12) : null, 'transmitted_at' => $i->transmitted_at?->toDateString(), 'note' => $i->transmission_note])->values(),
        ]);
    }

    public function export(Request $request, Company $company)
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:'.TransmissionService::MAX_BATCH], 'ids.*' => ['integer'], 'pdf' => ['boolean']]);
        $z = $this->svc->zip($company, $request->user(), $d['ids'], (bool) ($d['pdf'] ?? false));

        return response()->download($z['path'], $z['filename'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    public function mark(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:'.TransmissionService::MAX_BATCH], 'ids.*' => ['integer'], 'date' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:255']]);
        $n = $this->svc->mark($company, $request->user(), $d['ids'], $d['date'], $d['note'] ?? null);

        return back()->with('success', $n === 1 ? 'Fattura segnata come trasmessa.' : "{$n} fatture segnate come trasmesse.");
    }

    public function unmark(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:'.TransmissionService::MAX_BATCH], 'ids.*' => ['integer']]);
        $n = $this->svc->unmark($company, $request->user(), $d['ids']);

        return back()->with('success', $n === 1 ? 'Fattura di nuovo da trasmettere.' : "{$n} fatture di nuovo da trasmettere.");
    }
}
