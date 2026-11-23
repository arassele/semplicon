<?php

namespace Tests\Feature;

use App\Domain\Scadenziario\PaymentService;
use App\Domain\VatProfileService;
use App\Models\Installment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Stampa: Registro IVA, Liquidazione, Registro incassi e pagamenti (solo professionisti). */
class PrintTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_print_page_registers_and_liquidation_pdfs(): void
    {
        $b = "/aziende/{$this->company->id}";
        $this->issueInvoice(['doc_date' => '2026-11-10']);
        $this->actingAs($this->owner)->get("$b/stampa?anno=2026")->assertOk()->assertInertia(fn ($p) => $p->component('Print/Index')->where('year', 2026)->has('periods', 12)->where('cashRegister', false));
        $this->actingAs($this->owner)->get("$b/stampa/incassi-pagamenti")->assertNotFound(); // non per la semplificata
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->owner)->get("$b/registri/vendite/pdf?anno=2026&periodo=11")->getContent());
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->owner)->get("$b/liquidazioni/2026/11/pdf")->getContent());
    }

    public function test_cash_register_for_professionals(): void
    {
        $b = "/aziende/{$this->company->id}";
        $inv = $this->issueInvoice(['doc_date' => '2026-11-10']);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2027);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'professionisti', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
        app(PaymentService::class)->record($this->company, $this->owner, ['kind' => 'incasso', 'movement_date' => '2026-11-20', 'amount' => '1220.00', 'allocations' => [['installment_id' => Installment::where('invoice_id', $inv->id)->value('id'), 'amount' => '1220.00']], 'request_id' => 'k1']);
        // L'anno 2027 è professionisti, il 2026 no
        $this->actingAs($this->owner)->get("$b/stampa?anno=2027")->assertInertia(fn ($p) => $p->where('cashRegister', true));
        $r = $this->actingAs($this->owner)->get("$b/stampa/incassi-pagamenti?anno=2027");
        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertDatabaseHas('audit_events', ['action' => 'register.exported']);
        // bloccato se il modulo Scadenziario è disattivato
        $this->company->update(['options' => ['modules' => ['scadenziario' => false]]]);
        $this->actingAs($this->owner)->get("$b/stampa/incassi-pagamenti?anno=2027")->assertRedirect($b);
    }
}
