<?php

namespace Tests\Feature;

use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\Issuing\InvoiceXmlView;
use App\Domain\ManualInvoiceService;
use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\XmlExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Anteprima e PDF dal file FatturaPA: contenuto ricavato dall'XML, anteprima prima dell'emissione, accessi. */
class InvoicePdfTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_html_preview_and_pdf_come_from_the_xml(): void
    {
        $inv = $this->issueInvoice(['causale' => 'Consulenza ottobre', 'lines' => [['description' => 'Consulenza strategica', 'quantity' => '10', 'unit' => 'ore', 'price' => '100,00', 'discount' => '10', 'rate' => '22', 'natura' => '']], 'payments' => [['date' => '2026-12-18', 'amount' => '1098,00']]]);
        $b = "/aziende/{$this->company->id}/fatture/{$inv->id}";
        $this->actingAs($this->owner)->get("$b/anteprima-xml")->assertOk()->assertInertia(fn ($p) => $p->component('Invoices/Preview')->where('invoice.number', '1/2026'));
        $html = $this->actingAs($this->owner)->get("$b/anteprima-xml/documento")->assertOk()->assertHeader('Content-Security-Policy')->getContent();
        foreach (['Fattura n. 1/2026', 'Consulenza strategica', 'Consulenza ottobre', 'Cliente Uno S.r.l.', 'Alpha S.r.l.', '1.098,00', '900,00', '198,00', '18/12/2026'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('Anteprima</strong> · la fattura non è ancora emessa', $html);
        $pdf = $this->actingAs($this->owner)->get("$b/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(1, AuditEvent::where('action', 'invoice.pdf_exported')->count());
        $this->assertStringContainsString('fattura-1-2026.pdf', $pdf->headers->get('Content-Disposition'));
    }

    public function test_view_model_reads_ritenuta_cassa_bollo_and_credit_note_link(): void
    {
        $xml = XmlExport::where('id', '>', 0)->value('content') ?? app(InvoiceIssuer::class)->previewXml($this->company, $this->invoiceData(['doc_type' => 'TD04', 'ref_number' => '1/2026', 'ref_date' => '2026-11-18', 'payments' => []]));
        $doc = app(InvoiceXmlView::class)->fromXml($xml);
        $b = $doc['bodies'][0];
        $this->assertSame('TD04', $b['type']);
        $this->assertSame([['number' => '1/2026', 'date' => '2026-11-18']], $b['links']);
        $this->assertSame('1000.00', $b['taxable']);
        $this->assertSame('1220.00', $b['total']);
        $this->assertSame('Alpha S.r.l.', $doc['cedente']['name']);
        $this->assertSame('IT01234567897', $doc['cedente']['vat']);
    }

    public function test_draft_preview_does_not_issue_and_reports_errors(): void
    {
        $url = "/aziende/{$this->company->id}/fatture/emetti/documento";
        $r = $this->actingAs($this->owner)->postJson($url, $this->invoiceData())->assertOk();
        $this->assertStringContainsString('Anteprima</strong> · la fattura non è ancora emessa', $r->json('html'));
        $this->assertStringContainsString('Consulenza', $r->json('html'));
        $this->assertSame(0, Invoice::count());
        $this->assertSame('1/2026', app(InvoiceIssuer::class)->peekNumber($this->company, 2026)); // nessun numero consumato
        $bad = $this->invoiceData();
        $bad['lines'][0]['price'] = 'abc';
        $this->actingAs($this->owner)->postJson($url, $bad)->assertStatus(422)->assertJsonValidationErrors('lines.0.price');
    }

    public function test_access_is_limited_to_the_company_and_to_invoices_with_xml(): void
    {
        $inv = $this->issueInvoice();
        $b = "/aziende/{$this->company->id}/fatture/{$inv->id}";
        $this->actingAs($this->customer())->get("$b/pdf")->assertNotFound();
        $manual = Invoice::where('id', '!=', $inv->id)->first();
        $this->assertNull($manual);
        $m = app(ManualInvoiceService::class)->create($this->company, $this->owner, ['direction' => 'acquisto', 'doc_type' => 'TD01', 'number' => 'M-1', 'doc_date' => '2026-11-10', 'counterparty_name' => 'Forn', 'counterparty_vat' => '22222222222', 'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '100.00', 'tax' => '22.00', 'description' => 'x']]]);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$m->id}/pdf")->assertNotFound(); // manuale senza XML
    }

    public function test_logo_upload_is_reencoded_shown_on_preview_pdf_and_document_is_grayscale(): void
    {
        $b = "/aziende/{$this->company->id}";
        $inv = $this->issueInvoice();
        $this->assertStringNotContainsString('data:image/png', $this->actingAs($this->owner)->get("$b/fatture/{$inv->id}/anteprima-xml/documento")->getContent());
        $img = imagecreatetruecolor(1200, 300);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 60));
        $tmp = tempnam(sys_get_temp_dir(), 'lg').'.png';
        imagepng($img, $tmp);
        $file = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);
        $this->actingAs($this->owner)->post("$b/dati-azienda/logo", ['logo' => $file])->assertSessionDoesntHaveErrors();
        $this->assertNotNull($this->company->fresh()->logo_path);
        $stored = Storage::disk('local')->get($this->company->fresh()->logo_path);
        [$w, $h] = getimagesizefromstring($stored);
        $this->assertSame([600, 150], [$w, $h]);
        $html = $this->actingAs($this->owner)->get("$b/fatture/{$inv->id}/anteprima-xml/documento")->getContent();
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->owner)->get("$b/fatture/{$inv->id}/pdf")->getContent());
        $this->assertDoesNotMatchRegularExpression('/#(003d35|edf9f1|47e58a|17603d)/i', $html); // nessun colore verde
        preg_match('#data:image/png;base64,([A-Za-z0-9+/=]+)#', $html, $m);
        $px = imagecreatefromstring(base64_decode($m[1]));
        $c = imagecolorsforindex($px, imagecolorat($px, 5, 5));
        $this->assertSame([$c['red'], $c['red']], [$c['green'], $c['blue']]); // logo in scala di grigi
        $this->actingAs($this->owner)->get("$b/dati-azienda/logo")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->customer())->get("$b/dati-azienda/logo")->assertNotFound();
        // nessuna immagine
        $bad = UploadedFile::fake()->create('x.png', 5, 'image/png');
        $this->actingAs($this->owner)->post("$b/dati-azienda/logo", ['logo' => $bad])->assertSessionHasErrors('logo');
        $this->actingAs($this->owner)->delete("$b/dati-azienda/logo");
        $this->assertNull($this->company->fresh()->logo_path);
    }
}
