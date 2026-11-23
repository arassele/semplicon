<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\Liquidazioni\LipeService;
use App\Models\Company;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** LIPE: file IVP18 per la trasmissione telematica e stampa di controllo per trimestre. */
class LipeController extends Controller
{
    public function __construct(private LipeService $lipe) {}

    private function quarter(Company $company, int $year, int $quarter): array
    {
        abort_if($quarter < 1 || $quarter > 4 || $year < 2000 || $year > 2100, 404);

        return $this->lipe->build($company, $year, $quarter);
    }

    public function xml(Request $request, Company $company, int $year, int $quarter): Response
    {
        $b = $this->quarter($company, $year, $quarter);
        abort_unless($b['ok'], 422, implode(' ', $b['blocking']));
        Audit::record('lipe.exported', $request->user(), $company, data: ['year' => $year, 'quarter' => $quarter, 'format' => 'xml', 'modules' => count($b['modules'])]);

        return response($this->lipe->xml($company, $b), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$b['filename'].'"']);
    }

    /** Stampa di controllo: possibile anche con punti aperti (con avviso), per poter verificare prima di confermare. */
    public function pdf(Request $request, Company $company, int $year, int $quarter): Response
    {
        $b = $this->quarter($company, $year, $quarter);
        Audit::record('lipe.exported', $request->user(), $company, data: ['year' => $year, 'quarter' => $quarter, 'format' => 'pdf', 'modules' => count($b['modules'])]);
        $o = new Options;
        $o->setIsRemoteEnabled(false);
        $o->setDefaultFont('DejaVu Sans');
        $d = new Dompdf($o);
        $d->loadHtml(view('pdf.lipe', ['company' => $company, 'b' => $b, 'generatedAt' => now()])->render(), 'UTF-8');
        $d->setPaper('A4', 'landscape');
        $d->render();

        return response($d->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="lipe-controllo-'.$year.'-T'.$quarter.'.pdf"']);
    }
}
