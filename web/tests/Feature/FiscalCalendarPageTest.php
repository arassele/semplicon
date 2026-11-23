<?php

namespace Tests\Feature;

use App\Domain\VatProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

class FiscalCalendarPageTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    public function test_scadenze_fiscali_page_shows_calendar_for_selected_year(): void
    {
        $this->setUpIssuing();
        $b = "/aziende/{$this->company->id}/scadenziario/scadenze-fiscali";
        $this->actingAs($this->owner)->get("$b?anno=2026")->assertOk()->assertInertia(fn ($p) => $p->where('year', 2026)->where('periodicity', 'mensile')->has('calendar')->where('calendar.0.date', '2026-01-15'));
        app(VatProfileService::class)->createDraft($this->company, $this->owner, 2027);
        // 2027 senza regime confermato: solo le scadenze che non dipendono dalla periodicità
        $this->actingAs($this->owner)->get("$b?anno=2027")->assertInertia(fn ($p) => $p->where('periodicity', null)->where('calendar', fn ($c) => collect($c)->where('type', 'iva')->isEmpty() && collect($c)->where('type', 'lipe')->count() === 4));
    }
}
