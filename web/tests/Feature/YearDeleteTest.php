<?php

namespace Tests\Feature;

use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\VatProfileService;
use App\Models\AuditEvent;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\VatProfile;
use App\Models\VatRegistration;
use App\Models\XmlExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Eliminare un anno: solo con password, rimuove tutti i dati dell'anno, lascia intatti gli altri anni. */
class YearDeleteTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_requires_password_removes_year_data_and_resets_numbering(): void
    {
        $b = "/aziende/{$this->company->id}";
        $this->owner->update(['password' => Hash::make('segreto-lungo-12')]);
        $inv = $this->issueInvoice(['doc_date' => '2026-11-18']);
        $this->issueInvoice(['doc_date' => '2026-11-19']);
        // Anno 2025 con fattura propria (manuale, regime confermato)
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2025);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
        $old = app(InvoiceIssuer::class)->issue($this->company, $this->owner, $this->invoiceData(['doc_date' => '2025-12-10']));
        $this->assertSame('1/2025', $old->number);
        $this->assertSame(3, Invoice::count());

        $url = "$b/profilo-iva/anno/2026/elimina";
        $this->actingAs($this->owner)->get("$b/profilo-iva?anno=2026")->assertInertia(fn ($p) => $p->where('yearDelete.summary.invoices', 2)->where('yearDelete.summary.issued', 2)->where('yearDelete.blocker', null));
        $this->actingAs($this->owner)->post($url, ['password' => 'falsch'])->assertSessionHasErrors('password');
        $this->assertSame(3, Invoice::count()); // nulla eliminato
        $this->actingAs($this->owner)->post($url, [])->assertSessionHasErrors('password');

        $this->actingAs($this->owner)->post($url, ['password' => 'segreto-lungo-12'])->assertRedirect($b);
        $this->assertSame([1, 0, 0], [Invoice::count(), Installment::where('invoice_id', $inv->id)->count(), XmlExport::where('invoice_id', $inv->id)->count()]);
        $this->assertSame($old->id, Invoice::sole()->id);
        $this->assertSame(1, VatRegistration::count());
        $this->assertFalse(VatProfile::where('year', 2026)->exists());
        $this->assertTrue(VatProfile::where('year', 2025)->exists());
        $ev = AuditEvent::where('action', 'year.deleted_summary')->sole();
        $this->assertSame([2026, 2], [$ev->data['year'], $ev->data['issued']]);
        // l'anno eliminato (corrente) non è più selezionabile, la selezione torna al 2025
        $this->actingAs($this->owner)->post("$b/anno", ['year' => 2026])->assertStatus(422);
        $this->actingAs($this->owner)->get($b)->assertInertia(fn ($p) => $p->where('currentCompany.fiscal_year.years', [2025])->where('currentCompany.fiscal_year.active', 2025));
        $this->actingAs($this->owner)->get("$b/vendite?anno=2026")->assertInertia(fn ($p) => $p->where('filters.anno', 2025));
        // Ricrearlo lo rende di nuovo selezionabile
        app(VatProfileService::class)->createDraft($this->company->fresh(), $this->owner, 2026);
        $this->actingAs($this->owner)->get($b)->assertInertia(fn ($p) => $p->where('currentCompany.fiscal_year.years', [2026, 2025]));
        $this->actingAs($this->owner)->post("$b/anno", ['year' => 2026])->assertSessionHasNoErrors();
        // un anno senza dati e senza regime (non quello corrente) non esiste
        $this->actingAs($this->owner)->post("$b/profilo-iva/anno/2023/elimina", ['password' => 'segreto-lungo-12'])->assertSessionHasErrors('year');
        $this->actingAs($this->owner)->post("$b/anno", ['year' => 2023])->assertStatus(422);
    }

    public function test_other_users_and_rate_limit(): void
    {
        $b = "/aziende/{$this->company->id}/profilo-iva/anno/2026/elimina";
        $this->owner->update(['password' => Hash::make('segreto-lungo-12')]);
        $this->actingAs($this->customer())->post($b, ['password' => 'x'])->assertNotFound();
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->owner)->post($b, ['password' => 'falsch'])->assertSessionHasErrors('password');
        }
        // bloccato: anche la password giusta viene ora respinta
        $r = $this->actingAs($this->owner)->post($b, ['password' => 'segreto-lungo-12']);
        $r->assertSessionHasErrors('password');
        $this->assertTrue(VatProfile::where('year', 2026)->exists());
    }
}
