<?php

namespace Tests\Feature;

use App\Domain\CompanyOptions;
use App\Domain\Scadenziario\PaymentService;
use App\Domain\VatProfileService;
use App\Models\Installment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Funzioni e opzioni: disattivare un modulo lo nasconde e lo blocca, fonte della data di pagamento. */
class CompanyOptionsTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_modules_can_be_switched_off_hidden_and_blocked(): void
    {
        $b = "/aziende/{$this->company->id}";
        $this->issueInvoice();
        $this->actingAs($this->owner)->get("$b/impostazioni/funzioni")->assertOk()->assertInertia(fn ($p) => $p->where('options.modules.scadenziario', true)->where('options.import_check_vat', true));
        $this->actingAs($this->owner)->get("$b/scadenziario/da-incassare")->assertOk();

        $this->actingAs($this->owner)->put("$b/impostazioni/funzioni", ['modules' => ['scadenziario' => false, 'emissione' => false, 'adempimenti' => false], 'payment_date_source' => 'registrazione', 'import_check_vat' => false])->assertSessionDoesntHaveErrors();
        $this->assertFalse(CompanyOptions::enabled($this->company->fresh(), 'scadenziario'));
        foreach (['/scadenziario/da-incassare', '/fatture/emetti', '/fatture-elettroniche', '/fatture/ricorrenti', '/liquidazioni', '/crediti-iva', '/operazioni-transfrontaliere', '/archivio', '/registri/vendite', '/scadenziario/scadenze-fiscali'] as $path) {
            $this->actingAs($this->owner)->get("$b$path")->assertRedirect($b);
        }
        $this->actingAs($this->owner)->get("$b/vendite")->assertOk()->assertInertia(fn ($p) => $p->where('currentCompany.modules.emissione', false));
        $this->actingAs($this->owner)->get($b)->assertInertia(fn ($p) => $p->where('overview.areas', fn ($a) => collect($a)->pluck('id')->diff(['documenti', 'anagrafiche'])->isEmpty()));
        $this->actingAs($this->owner)->post("$b/fatture/emetti/documento", $this->invoiceData())->assertRedirect($b);
        // I dati restano, la riattivazione ripristina tutto
        $this->actingAs($this->owner)->put("$b/impostazioni/funzioni", ['modules' => ['scadenziario' => true, 'emissione' => true, 'adempimenti' => true], 'payment_date_source' => 'scadenziario', 'import_check_vat' => true]);
        $this->actingAs($this->owner)->get("$b/fatture-elettroniche")->assertOk()->assertInertia(fn ($p) => $p->has('invoices', 1));
        $this->assertDatabaseHas('audit_events', ['action' => 'company.options_updated']);
    }

    public function test_payment_date_source_competenza_is_always_registration_date(): void
    {
        // Il regime di test è «semplificata per cassa»: fonte secondo l'impostazione
        $this->assertSame('scadenziario', CompanyOptions::paymentDateSource($this->company, 2026));
        $this->company->update(['options' => ['payment_date_source' => 'registrazione']]);
        $this->assertSame('registrazione', CompanyOptions::paymentDateSource($this->company->fresh(), 2026));
        $this->company->update(['options' => ['payment_date_source' => 'scadenziario']]);
        // Regime per un anno «per competenza» → sempre data di registrazione
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2027);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'competenza', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
        $this->assertSame('registrazione', CompanyOptions::paymentDateSource($this->company->fresh(), 2027));
    }

    public function test_register_shows_payment_date_from_scadenziario_or_registration(): void
    {
        $b = "/aziende/{$this->company->id}";
        $inv = $this->issueInvoice(['doc_date' => '2026-11-10']);
        $inst = Installment::where('invoice_id', $inv->id)->first();
        $this->actingAs($this->owner)->get("$b/registri/vendite?anno=2026")->assertInertia(fn ($p) => $p->where('data.payment_title', 'Data incasso')->where('data.entries.0.payment.source', 'scadenziario')->where('data.entries.0.payment.label', '—'));
        // Registrare l'incasso al 20.11.
        app(PaymentService::class)->record($this->company, $this->owner, ['kind' => 'incasso', 'movement_date' => '2026-11-20', 'amount' => '1220.00', 'allocations' => [['installment_id' => $inst->id, 'amount' => '1220.00']], 'request_id' => 'r1']);
        $this->actingAs($this->owner)->get("$b/registri/vendite?anno=2026")->assertInertia(fn ($p) => $p->where('data.entries.0.payment.label', '20/11/2026'));
        $csv = $this->actingAs($this->owner)->get("$b/registri/vendite/csv?anno=2026")->getContent();
        $this->assertStringContainsString('Data incasso', $csv);
        $this->assertStringContainsString('20/11/2026', $csv);
        // Fonte «registrazione»: data di registrazione, indipendente dallo Scadenziario
        $this->company->update(['options' => ['payment_date_source' => 'registrazione']]);
        $this->actingAs($this->owner)->get("$b/registri/vendite?anno=2026")->assertInertia(fn ($p) => $p->where('data.entries.0.payment.source', 'registrazione')->where('data.entries.0.payment.label', '10/11/2026'));
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->owner)->get("$b/registri/vendite/pdf?anno=2026")->getContent());
    }
}
