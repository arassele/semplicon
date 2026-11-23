<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\Issuing\InvoiceXmlView;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\XmlExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Anteprima e PDF della fattura, ricavati dal file FatturaPA (XML). */
class InvoiceDocumentController extends Controller
{
    public function __construct(private InvoiceXmlView $view) {}

    public function preview(Company $company, Invoice $invoice): InertiaResponse
    {
        $this->xml($invoice); // 404 se non c'è un XML leggibile

        return Inertia::render('Invoices/Preview', ['invoice' => ['id' => $invoice->id, 'number' => $invoice->number, 'doc_date' => $invoice->doc_date->toDateString(), 'source' => $invoice->source, 'direction' => $invoice->direction?->value]]);
    }

    /** Il documento come HTML (incorporato nella pagina di anteprima). */
    public function document(Company $company, Invoice $invoice): Response
    {
        $doc = $this->view->fromXml($this->xml($invoice));
        $html = $this->view->html($doc, max(0, (int) $invoice->body_index - 1), false, $this->view->logoFor($company, $doc));

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'", 'X-Content-Type-Options' => 'nosniff']);
    }

    public function pdf(Request $request, Company $company, Invoice $invoice): Response
    {
        $doc = $this->view->fromXml($this->xml($invoice));
        $pdf = $this->view->pdf($this->view->html($doc, max(0, (int) $invoice->body_index - 1), false, $this->view->logoFor($company, $doc)));
        Audit::record('invoice.pdf_exported', $request->user(), $company, $invoice, data: ['number' => $invoice->number]);
        $name = 'fattura-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->number).'.pdf';

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Anteprima dal modulo di emissione: HTML del file XML che verrebbe creato (non emette nulla). */
    public function draft(Request $request, Company $company, InvoiceIssuer $issuer): JsonResponse
    {
        $xml = $issuer->previewXml($company, $request->all());

        $doc = $this->view->fromXml($xml);

        return response()->json(['html' => $this->view->html($doc, 0, true, $this->view->logoFor($company, $doc))]);
    }

    private function xml(Invoice $invoice): string
    {
        if ($invoice->source === 'emessa') {
            $x = XmlExport::where('company_id', $invoice->company_id)->where('invoice_id', $invoice->id)->orderByDesc('version')->first();
            if ($x) {
                return $x->content;
            }
        }
        $doc = $invoice->document;
        abort_unless($doc && $doc->kind === 'xml' && Storage::disk('local')->exists($doc->storage_path), 404);

        return Storage::disk('local')->get($doc->storage_path);
    }
}
