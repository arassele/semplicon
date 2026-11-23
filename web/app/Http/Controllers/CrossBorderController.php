<?php

namespace App\Http\Controllers;

use App\Domain\Esterometro\CrossBorderService;
use App\Domain\Esterometro\IntegrationService;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\XmlExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** IVA › Operazioni transfrontaliere (sezione 7). */
class CrossBorderController extends Controller
{
    public function index(Request $request, Company $company, CrossBorderService $svc): Response
    {
        $anno = (string) $request->query('anno', (string) now()->year);
        $year = $anno === 'tutti' ? null : (int) $anno;
        $rows = $svc->rows($company, $year);

        return Inertia::render('CrossBorder/Index', [
            'rows' => $rows,
            'anno' => $anno,
            'years' => collect($svc->rows($company, null))->map(fn ($r) => (int) substr($r['trigger'], 0, 4))->push(now()->year)->unique()->sortDesc()->values(),
            'kinds' => IntegrationService::KINDS,
            'xmlErrors' => session('xml_errors'),
            'xmlErrorsFor' => session('xml_errors_for'),
        ]);
    }

    public function classify(Request $request, Company $company, Invoice $invoice, CrossBorderService $svc): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $d = $request->validate(['kind' => ['nullable', 'string'], 'relevant' => ['nullable', 'boolean'], 'customs' => ['boolean']]);
        $svc->classify($company, $invoice, $request->user(), $d);

        return back()->with('success', "Fattura {$invoice->number}: classificazione salvata (nessuna registrazione modificata).");
    }

    public function generate(Request $request, Company $company, Invoice $invoice, CrossBorderService $svc): RedirectResponse
    {
        Gate::authorize('manage', $company);
        $r = $svc->generate($company, $invoice, $request->user());
        if ($r['errors']) {
            return back()->with('xml_errors', $r['errors'])->with('xml_errors_for', $invoice->id);
        }

        return back()->with('success', "XML {$r['export']->doc_type} della fattura {$invoice->number} generato (versione {$r['export']->version}). Nessuna registrazione creata.");
    }

    public function download(Request $request, Company $company, XmlExport $xmlExport, CrossBorderService $svc)
    {
        abort_unless($xmlExport->company_id === $company->id, 404);
        $svc->downloaded($company, $xmlExport, $request->user());

        return response($xmlExport->content, 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="'.$xmlExport->filename.'"']);
    }
}
