<?php

namespace Tests\Feature;

use App\Domain\VatProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Anno contabile centrale: gli elenchi mostrano l'anno scelto, il cambio vale per tutte le pagine. */
class ActiveYearTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_default_year_is_injected_and_switch_applies_everywhere(): void
    {
        $b = "/aziende/{$this->company->id}";
        $this->issueInvoice(['doc_date' => '2026-11-18']);
        $this->actingAs($this->owner)->get("$b/vendite")->assertInertia(fn ($p) => $p->where('filters.anno', 2026)->where('auth.user.id', $this->owner->id)->has('currentCompany.fiscal_year.years'));
        // creare un secondo anno, cambiare
        $svc = app(VatProfileService::class);
        $svc->createDraft($this->company, $this->owner, 2025);
        $r = $this->actingAs($this->owner)->from("$b/vendite?anno=2026&stato=aperte")->post("$b/anno", ['year' => 2025]);
        $r->assertRedirect("$b/vendite?stato=aperte"); // ?anno viene rimosso, altrimenti reimposterebbe l'anno
        $this->actingAs($this->owner)->get("$b/vendite")->assertInertia(fn ($p) => $p->where('filters.anno', 2025)->where('currentCompany.fiscal_year.active', 2025));
        $this->actingAs($this->owner)->get("$b/registri/vendite")->assertInertia(fn ($p) => $p->where('year', 2025));
        // ?anno esplicito sceglie in modo centrale, tutti no
        $this->actingAs($this->owner)->get("$b/vendite?anno=2026")->assertOk();
        $this->actingAs($this->owner)->get("$b/vendite")->assertInertia(fn ($p) => $p->where('filters.anno', 2026));
        $this->actingAs($this->owner)->get("$b/vendite?anno=tutti")->assertInertia(fn ($p) => $p->where('filters.anno', 'tutti'));
        $this->actingAs($this->owner)->get("$b/vendite")->assertInertia(fn ($p) => $p->where('filters.anno', 2026));
        $this->actingAs($this->owner)->post("$b/anno", ['year' => 1999])->assertStatus(422);
    }

    public function test_dashboard_areas_and_analysis_follow_the_active_year(): void
    {
        $b = "/aziende/{$this->company->id}";
        $inv = $this->issueInvoice(['doc_date' => '2026-11-18']); // 1000 netto, registrazione novembre
        $this->actingAs($this->owner)->get($b)->assertOk()->assertInertia(function ($p) {
            $p->where('overview.year', 2026)->where('overview.analysis.accrual.in_total', 100000)->where('overview.analysis.accrual.vat_out', 22000)->where('overview.analysis.accrual.in.10', 100000)
                ->where('overview.analysis.cash.in_total', 0)->has('overview.areas', 5)->where('overview.areas.0.id', 'fatturazione')->where('overview.areas.0.items.1.value', '1');
        });
        $this->assertNotNull($inv);
        app(VatProfileService::class)->createDraft($this->company, $this->owner, 2025);
        $this->actingAs($this->owner)->post("$b/anno", ['year' => 2025]);
        $this->actingAs($this->owner)->get($b)->assertInertia(fn ($p) => $p->where('overview.year', 2025)->where('overview.analysis.accrual.in_total', 0)->where('currentYear', 2025));
    }
}
