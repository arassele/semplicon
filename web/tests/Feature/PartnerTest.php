<?php

namespace Tests\Feature;

use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Anagrafica clienti/fornitori. */
class PartnerTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_create_validate_edit_delete_and_isolation(): void
    {
        $b = "/aziende/{$this->company->id}/anagrafiche";
        $ok = ['is_customer' => true, 'kind' => 'azienda', 'name' => 'Cliente Uno S.r.l.', 'vat' => '11111111115', 'country' => 'IT', 'address' => 'Via Verdi 1', 'zip' => '40121', 'city' => 'Bologna', 'province' => 'BO', 'sdi_code' => 'abc1234', 'payment_mode' => 'MP05', 'payment_days' => 60];
        $this->actingAs($this->owner)->post($b, [...$ok, 'vat' => '11111111111'])->assertSessionHasErrors('vat');
        $this->actingAs($this->owner)->post($b, [...$ok, 'is_customer' => false])->assertSessionHasErrors('is_customer');
        $this->actingAs($this->owner)->post($b, [...$ok, 'iban' => 'IT00'])->assertSessionHasErrors('iban');
        $this->actingAs($this->owner)->post($b, $ok)->assertSessionDoesntHaveErrors();
        $p = Partner::sole();
        $this->assertSame(['IT11111111115', 'ABC1234', 60], [$p->vat, $p->sdi_code, $p->payment_days]);
        $this->actingAs($this->owner)->post($b, $ok)->assertSessionHasErrors('vat'); // duplicato
        $this->actingAs($this->owner)->put("$b/{$p->id}", [...$ok, 'is_supplier' => true, 'name' => 'Cliente Uno SpA'])->assertSessionDoesntHaveErrors();
        $this->assertTrue($p->fresh()->is_supplier);
        $this->actingAs($this->owner)->get("$b/clienti")->assertOk()->assertInertia(fn ($pg) => $pg->has('partners', 1)->where('counts.fornitori', 1));
        $this->actingAs($this->owner)->get("$b/fornitori?q=uno")->assertInertia(fn ($pg) => $pg->has('partners', 1));
        $this->actingAs($this->owner)->get("$b/fornitori?q=zzz")->assertInertia(fn ($pg) => $pg->has('partners', 0));
        $this->actingAs($this->customer())->delete("$b/{$p->id}")->assertNotFound();
        $this->actingAs($this->owner)->delete("$b/{$p->id}");
        $this->assertSame(0, Partner::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'partner.deleted']);
    }

    public function test_import_from_invoices_and_use_in_issue_form(): void
    {
        $this->issueInvoice();
        $b = "/aziende/{$this->company->id}/anagrafiche";
        $this->actingAs($this->owner)->post("$b/importa");
        $p = Partner::sole();
        $this->assertTrue($p->is_customer);
        $this->assertSame('IT11111111115', $p->vat);
        $this->actingAs($this->owner)->post("$b/importa"); // idempotente
        $this->assertSame(1, Partner::count());
        $p->update(['payment_days' => 45]);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/emetti")->assertInertia(fn ($pg) => $pg->has('customers', 1)->where('customers.0.master', true)->where('customers.0.payment_days', 45));
    }
}
