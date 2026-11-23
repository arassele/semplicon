<?php

namespace Tests\Feature;

use App\Domain\VatProfileService;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;
use ZipArchive;

/** Panoramica delle fatture XML, esportazione collettiva, stato trasmessa. */
class ElectronicInvoiceTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_list_filters_export_zip_and_status(): void
    {
        $a = $this->issueInvoice(['doc_date' => '2026-11-10']);
        $b = $this->issueInvoice(['doc_date' => '2026-11-12']);
        $c = "/aziende/{$this->company->id}/fatture-elettroniche";
        $this->actingAs($this->owner)->get($c)->assertOk()->assertInertia(fn ($p) => $p->has('invoices', 2)->where('counts.da_trasmettere', 2)->where('filters.anno', 2026));
        app(VatProfileService::class)->createDraft($this->company, $this->owner, 2025); // l'anno deve essere creato
        $this->actingAs($this->owner)->get("$c?anno=2025")->assertInertia(fn ($p) => $p->has('invoices', 0));

        // ZIP con XML e PDF
        $res = $this->actingAs($this->owner)->post("$c/esporta", ['ids' => [$a->id, $b->id], 'pdf' => true])->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($tmp, $res->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $this->assertCount(2, array_filter($names, fn ($n) => str_starts_with($n, 'XML/IT01234567897_')));
        $this->assertCount(2, array_filter($names, fn ($n) => str_starts_with($n, 'PDF/fattura-')));
        $this->assertContains('SHA256SUMS', $names);
        $this->assertSame(1, AuditEvent::where('action', 'xml.exported_bulk')->count());

        // Impostare lo stato: data non nel futuro, non prima della fattura
        $this->actingAs($this->owner)->post("$c/trasmessa", ['ids' => [$a->id], 'date' => '2026-11-30'])->assertSessionHasErrors('date');
        $this->actingAs($this->owner)->post("$c/trasmessa", ['ids' => [$b->id], 'date' => '2026-11-11'])->assertSessionHasErrors('date');
        $this->actingAs($this->owner)->post("$c/trasmessa", ['ids' => [$a->id, $b->id], 'date' => '2026-11-15', 'note' => 'Inviata con Programma X'])->assertSessionDoesntHaveErrors();
        $this->assertSame('2026-11-15', $a->fresh()->transmitted_at->toDateString());
        $this->actingAs($this->owner)->get("$c?anno=2026&stato=trasmesse")->assertInertia(fn ($p) => $p->has('invoices', 2)->where('counts.da_trasmettere', 0)->where('invoices.0.note', 'Inviata con Programma X'));
        $this->actingAs($this->owner)->post("$c/da-trasmettere", ['ids' => [$a->id]]);
        $this->assertNull($a->fresh()->transmitted_at);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}")->assertInertia(fn ($p) => $p->where('navCounts.da_trasmettere', 1));
    }

    public function test_only_own_issued_invoices_and_limits(): void
    {
        $a = $this->issueInvoice();
        $c = "/aziende/{$this->company->id}/fatture-elettroniche";
        $this->actingAs($this->owner)->postJson("$c/esporta", ['ids' => [999999]])->assertStatus(422);
        $this->actingAs($this->owner)->postJson("$c/esporta", ['ids' => []])->assertStatus(422);
        $this->actingAs($this->customer())->postJson("$c/esporta", ['ids' => [$a->id]])->assertNotFound();
    }
}
