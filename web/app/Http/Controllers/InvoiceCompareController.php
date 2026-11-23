<?php

namespace App\Http\Controllers;

use App\Domain\Import\FatturaPaSchema;
use App\Models\Company;
use App\Models\Invoice;
use Inertia\Inertia;
use Inertia\Response;

/** Ticket 08: «Confronta documenti» – due fatture della stessa azienda affiancate. */
class InvoiceCompareController extends Controller
{
    public function show(Company $company, Invoice $invoice, int $other): Response
    {
        $b = $company->invoices()->findOrFail($other);
        $side = function (Invoice $i) {
            $i->load('vatSummaries', 'lines', 'document');

            return [
                'id' => $i->id, 'number' => $i->number, 'doc_type' => $i->doc_type, 'doc_type_label' => FatturaPaSchema::label('TipoDocumentoType', $i->doc_type) ?? $i->doc_type,
                'doc_date' => $i->doc_date->toDateString(), 'status_label' => $i->status->label(), 'source' => $i->source,
                'supplier' => $i->supplier_name.($i->supplier_vat ? " · {$i->supplier_vat}" : ''), 'customer' => $i->customer_name.($i->customer_vat ? " · {$i->customer_vat}" : ''),
                'taxable_total' => (string) $i->taxable_total, 'tax_total' => (string) $i->tax_total, 'total_amount' => $i->total_amount !== null ? (string) $i->total_amount : null,
                'file' => $i->document?->original_name, 'imported_at' => $i->created_at->toIso8601String(),
                'summaries' => $i->vatSummaries->map(fn ($s) => ['key' => $s->natura ?? (string) $s->rate, 'label' => $s->natura ?? str_replace('.', ',', (string) $s->rate).'%', 'taxable' => (string) $s->taxable, 'tax' => (string) $s->tax])->values(),
                'lines' => $i->lines->map(fn ($l) => ['no' => $l->line_no, 'description' => $l->description, 'total' => (string) $l->total, 'rate' => $l->natura ?? (string) $l->rate])->values(),
            ];
        };

        return Inertia::render('Invoices/Compare', ['a' => $side($invoice), 'b' => $side($b)]);
    }
}
