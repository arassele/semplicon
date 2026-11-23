<?php

namespace Tests\Feature;

use App\Domain\ManualInvoiceService;
use App\Domain\NonVatEntryService;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;
use ZipArchive;

/** Selezione multipla negli elenchi delle fatture e fuori campo; lente/documento; eliminare. */
class BulkListsTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_rows_expose_document_and_delete_flags_and_bulk_exports_work(): void
    {
        $b = "/aziende/{$this->company->id}";
        $a = $this->issueInvoice(['doc_date' => '2026-11-10']);
        $m = app(ManualInvoiceService::class)->create($this->company, $this->owner, ['direction' => 'acquisto', 'doc_type' => 'TD01', 'number' => 'M-1', 'doc_date' => '2026-11-11', 'counterparty_name' => 'Forn', 'counterparty_vat' => '22222222222',
            'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '100.00', 'tax' => '22.00', 'description' => 'x']]]);
        $this->actingAs($this->owner)->get("$b/fatture?anno=2026")->assertInertia(fn ($p) => $p->where('invoices.data', fn ($d) => collect($d)->firstWhere('id', $a->id)['has_xml'] === true && collect($d)->firstWhere('id', $a->id)['deletable'] === false
            && collect($d)->firstWhere('id', $m->id)['deletable'] === true && collect($d)->firstWhere('id', $m->id)['has_xml'] === false));

        // ZIP: solo la fattura emessa ha un documento (XML)
        $r = $this->actingAs($this->owner)->post("$b/fatture/seleziona/documenti", ['ids' => [$a->id, $m->id]])->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($tmp, $r->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $this->assertSame(1, $zip->numFiles);
        $this->assertStringStartsWith('fatture/1_2026_IT01234567897_', $zip->getNameIndex(0));
        $csv = $this->actingAs($this->owner)->post("$b/fatture/seleziona/csv", ['ids' => [$a->id, $m->id]])->assertOk()->getContent();
        $this->assertStringContainsString('Numero;Tipo;Data', $csv);
        $this->assertStringContainsString('M-1', $csv);
        $this->actingAs($this->owner)->post("$b/fatture/seleziona/documenti", ['ids' => [$m->id]])->assertNotFound(); // nessun documento
        $this->actingAs($this->customer())->post("$b/fatture/seleziona/csv", ['ids' => [$a->id]])->assertNotFound();

        // Eliminare: manuali sì, emesse bloccate
        $this->actingAs($this->owner)->post("$b/fatture/seleziona/elimina", ['ids' => [$a->id, $m->id]])->assertSessionHas('success');
        $this->assertNotNull(Invoice::find($a->id));
        $this->assertNull(Invoice::find($m->id));
        $this->assertStringContainsString('Non eliminate', (string) session('success'));
    }

    public function test_nonvat_selection_exports(): void
    {
        $b = "/aziende/{$this->company->id}";
        $acc = $this->company->accounts()->where('active', true)->where('iva_rilevante', false)->where('type', 'costo')->first();
        $e = app(NonVatEntryService::class)->create($this->company, $this->owner, ['kind' => 'costo', 'entry_date' => '2026-11-10', 'account_id' => $acc->id, 'description' => 'Bollo', 'amount' => '16,00'], null);
        $csv = $this->actingAs($this->owner)->post("$b/fuori-campo/seleziona/csv", ['ids' => [$e->id]])->assertOk()->getContent();
        $this->assertStringContainsString('Bollo', $csv);
        $this->assertStringContainsString('16', $csv);
        $this->actingAs($this->owner)->post("$b/fuori-campo/seleziona/documenti", ['ids' => [$e->id]])->assertNotFound();
    }
}
