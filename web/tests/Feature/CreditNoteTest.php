<?php

namespace Tests\Feature;

use App\Domain\Issuing\InvoiceIssuer;
use App\Models\Invoice;
use App\Models\XmlExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Nota di credito con un clic (elenco requisiti 2.2): precompilazione dalla fattura emessa, riferimento, collegamento. */
class CreditNoteTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_credit_note_form_is_prefilled_and_issuing_links_it(): void
    {
        $orig = $this->issueInvoice(['causale' => 'Consulenza ottobre', 'lines' => [['description' => 'Consulenza', 'quantity' => '10', 'unit' => 'ore', 'price' => '100,00', 'discount' => '10', 'rate' => '22', 'natura' => '']], 'payments' => [['date' => '2026-12-18', 'amount' => '1098,00']]]);
        $url = "/aziende/{$this->company->id}/fatture/{$orig->id}";
        $this->actingAs($this->owner)->get("$url/nota-di-credito")->assertOk()->assertInertia(fn ($p) => $p->component('Invoices/Issue')
            ->where('prefill.form.doc_type', 'TD04')->where('prefill.form.ref_number', '1/2026')->where('prefill.form.ref_date', '2026-11-18')
            ->where('prefill.form.lines.0.discount', '10')->where('prefill.form.customer.name', 'Cliente Uno S.r.l.')
            ->where('prefill.form.causale', 'Storno fattura n. 1/2026 del 18/11/2026'));
        $this->actingAs($this->owner)->get($url)->assertInertia(fn ($p) => $p->where('credit.can', true)->where('credit.notes', []));

        // Il modulo preparato si può emettere direttamente (TD04, riferimento nell'XML)
        $form = app(InvoiceIssuer::class)->creditNoteDraft($orig)['form'];
        $form['doc_date'] = '2026-11-19';
        $nc = app(InvoiceIssuer::class)->issue($this->company, $this->owner, [...$form, 'payments' => []]);
        $this->assertSame('TD04', $nc->doc_type);
        $this->assertSame('900.00', (string) $nc->taxable_total);
        $xml = XmlExport::where('invoice_id', $nc->id)->value('content');
        $this->assertStringContainsString('<TipoDocumento>TD04</TipoDocumento>', $xml);
        $this->assertStringContainsString('<IdDocumento>1/2026</IdDocumento>', $xml);

        $this->actingAs($this->owner)->get($url)->assertInertia(fn ($p) => $p->where('credit.notes.0.number', $nc->number));
        // Una nota di credito stessa non ne riceve un'altra
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$nc->id}/nota-di-credito")->assertStatus(302);
    }

    public function test_only_issued_invoices_can_be_credited(): void
    {
        $manual = new Invoice(['source' => 'manuale']);
        $this->assertFalse(app(InvoiceIssuer::class)->canCredit($manual));
    }
}
