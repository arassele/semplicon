<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\IncomeStatementService;
use App\Http\Middleware\ActiveAccountingYear;
use App\Models\Company;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Conto economico: per competenza, per cassa e ai fini fiscali per l'anno contabile scelto. */
class IncomeStatementController extends Controller
{
    public function __construct(private IncomeStatementService $svc) {}

    private function view(Request $request): string
    {
        $v = (string) $request->query('vista', 'fiscale');

        return array_key_exists($v, IncomeStatementService::VIEWS) ? $v : 'fiscale';
    }

    public function show(Request $request, Company $company): Response
    {
        $year = ActiveAccountingYear::current($request, $company);

        return Inertia::render('IncomeStatement/Show', ['data' => $this->svc->build($company, $year, $this->view($request))]);
    }

    public function csv(Request $request, Company $company): HttpResponse
    {
        $year = ActiveAccountingYear::current($request, $company);
        $d = $this->svc->build($company, $year, $v = $this->view($request));
        $it = fn ($x) => str_replace('.', ',', (string) $x);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $fiscal = $v === 'fiscale';
        fputcsv($out, ['Tipo', 'Sezione', 'Conto', 'Descrizione', 'Importo', ...($fiscal ? ['Importo fiscale (deducibile/imponibile)'] : [])], ';');
        foreach (['ricavi' => 'Ricavo', 'costi' => 'Costo'] as $k => $t) {
            foreach ($d[$k] as $g) {
                foreach ($g['rows'] as $r) {
                    fputcsv($out, [$t, $g['label'], $r['code'], $r['name'], $it($r['amount']), ...($fiscal ? [$it($r['fiscal'])] : [])], ';');
                }
            }
        }
        fputcsv($out, ['Totale', '', '', 'Ricavi', $it($d['totals']['ricavi']), ...($fiscal ? [$it($d['totals']['ricavi_fiscali'])] : [])], ';');
        fputcsv($out, ['Totale', '', '', 'Costi', $it($d['totals']['costi']), ...($fiscal ? [$it($d['totals']['costi_fiscali'])] : [])], ';');
        fputcsv($out, ['Totale', '', '', 'Risultato', $it($d['totals']['risultato']), ...($fiscal ? [$it($d['totals']['risultato_fiscale'])] : [])], ';');
        if ($fiscal && ($d['totals']['maggiorazioni'] ?? '0.00') !== '0.00') {
            fputcsv($out, ['Variazione', '', '', 'Maggiorazioni (super/iperammortamento)', '', '-'.$it($d['totals']['maggiorazioni'])], ';');
            fputcsv($out, ['Totale', '', '', 'Risultato fiscale dopo le variazioni', '', $it($d['totals']['risultato_dopo_variazioni'])], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        Audit::record('income_statement.exported', $request->user(), $company, data: ['year' => $year, 'view' => $v, 'format' => 'csv']);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"conto-economico-{$v}-{$year}.csv\""]);
    }

    public function pdf(Request $request, Company $company): HttpResponse
    {
        $year = ActiveAccountingYear::current($request, $company);
        $d = $this->svc->build($company, $year, $v = $this->view($request));
        Audit::record('income_statement.exported', $request->user(), $company, data: ['year' => $year, 'view' => $v, 'format' => 'pdf']);
        $o = new Options;
        $o->setIsRemoteEnabled(false);
        $o->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($o);
        $pdf->loadHtml(view('pdf.income_statement', ['company' => $company, 'd' => $d, 'generatedAt' => now()])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"conto-economico-{$v}-{$year}.pdf\""]);
    }
}
