<?php

namespace Tests\Feature;

use App\Domain\VatProfileService;
use App\Models\FiscalParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** I parametri fiscali valgono per anno; righe proprie per azienda, copia da un altro anno. */
class FiscalParametersYearTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_year_view_copy_edit_and_isolation(): void
    {
        $b = "/aziende/{$this->company->id}/tabelle/parametri-fiscali";
        FiscalParameter::create(['year' => 2026, 'ambito' => 'Telefonia', 'deducibilita' => '80%']);
        $n = FiscalParameter::whereNull('company_id')->where('year', 2026)->count(); // righe di riferimento (migrazione + queste)
        app(VatProfileService::class)->createDraft($this->company, $this->owner, 2027); // creare l'anno, altrimenti non selezionabile
        $this->actingAs($this->owner)->get("$b?anno=2026")->assertInertia(fn ($p) => $p->where('year', 2026)->has('parameters', $n)->where('parameters.0.own', false));
        $this->actingAs($this->owner)->get("$b?anno=2027")->assertInertia(fn ($p) => $p->where('year', 2027)->has('parameters', 0)->where('sourceYears', [2026]));

        $this->actingAs($this->owner)->post("$b/copia?anno=2027", ['from' => 2026])->assertSessionDoesntHaveErrors();
        $this->assertSame($n, FiscalParameter::where('company_id', $this->company->id)->count());
        $own = FiscalParameter::where('company_id', $this->company->id)->where('ambito', 'Telefonia')->sole();
        $this->assertSame([2027, 'Telefonia'], [$own->year, $own->ambito]);
        $this->actingAs($this->owner)->post("$b/copia?anno=2027", ['from' => 2026]); // idempotente
        $this->assertSame($n, FiscalParameter::where('company_id', $this->company->id)->count());
        $this->actingAs($this->owner)->put("$b/{$own->id}", ['ambito' => 'Telefonia', 'deducibilita' => '50%'])->assertSessionDoesntHaveErrors();
        $this->assertSame('50%', $own->fresh()->deducibilita);
        $this->actingAs($this->owner)->post("$b?anno=2027", ['ambito' => 'Carburante', 'fonte_url' => 'nicht-url'])->assertSessionHasErrors('fonte_url');
        // La riga di riferimento non è modificabile; l'azienda altrui non vede nulla
        $ref = FiscalParameter::whereNull('company_id')->first();
        $this->actingAs($this->owner)->put("$b/{$ref->id}", ['ambito' => 'x'])->assertNotFound();
        $other = $this->customer();
        $this->actingAs($other)->get($b)->assertNotFound();
        $this->actingAs($this->owner)->delete("$b/{$own->id}");
        $this->assertSame($n - 1, FiscalParameter::where('company_id', $this->company->id)->count());
    }
}
