<?php

namespace Tests\Feature;

use App\Domain\Issuing\RecurringInvoiceService;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;

/** Fatture ricorrenti (elenco requisiti 2.1). */
class RecurringInvoiceTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    private function template(array $over = []): RecurringInvoice
    {
        $p = $this->invoiceData(['causale' => 'Canone {mese} {anno}', 'lines' => [['description' => 'Assistenza {periodo}', 'quantity' => '1', 'unit' => 'pz', 'price' => '100,00', 'rate' => '22', 'natura' => '']]]);
        unset($p['doc_date'], $p['payments']);

        return app(RecurringInvoiceService::class)->create($this->company, $this->owner, ['name' => 'Canone', 'frequency' => 'mensile', 'start_date' => '2026-11-20', 'payment_days' => 30, ...$over, 'payload' => $p]);
    }

    public function test_create_via_http_validates_and_lists(): void
    {
        $p = $this->invoiceData();
        unset($p['payments']);
        $url = "/aziende/{$this->company->id}/fatture/ricorrenti";
        $this->actingAs($this->owner)->post($url, [...$p, 'recurring' => ['name' => 'X', 'frequency' => 'mai', 'start_date' => '2026-12-01']])->assertSessionHasErrors('recurring.frequency');
        $bad = $p;
        $bad['lines'][0]['price'] = 'abc';
        $this->actingAs($this->owner)->post($url, [...$bad, 'recurring' => ['frequency' => 'mensile', 'start_date' => '2026-12-01']])->assertSessionHasErrors('lines.0.price');
        $this->actingAs($this->owner)->post($url, [...$p, 'recurring' => ['name' => 'Canone', 'frequency' => 'trimestrale', 'start_date' => '2026-12-01', 'auto_issue' => true]])->assertSessionDoesntHaveErrors()->assertRedirect($url);
        $r = RecurringInvoice::sole();
        $this->assertSame([3, '2026-12-01', 1, true], [$r->payload ? 3 : 0, $r->next_date->toDateString(), $r->anchor_day, $r->auto_issue]);
        $this->assertArrayNotHasKey('payments', $r->payload);
        $this->actingAs($this->owner)->get($url)->assertOk()->assertInertia(fn ($pg) => $pg->has('items', 1)->where('items.0.due', false));
        $this->assertDatabaseHas('audit_events', ['action' => 'recurring.created']);
    }

    public function test_issue_now_creates_invoice_with_placeholders_and_advances(): void
    {
        $r = $this->template();
        $this->assertTrue(app(RecurringInvoiceService::class)->isDue($r));
        $inv = app(RecurringInvoiceService::class)->issueNow($this->company, $this->owner, $r);
        $this->assertSame(['1/2026', 'emessa', '2026-11-20', '100.00'], [$inv->number, $inv->source, $inv->doc_date->toDateString(), (string) $inv->taxable_total]);
        $this->assertSame('Canone novembre 2026', $inv->description);
        $this->assertSame('Assistenza novembre 2026', $inv->lines()->first()->description);
        $this->assertSame([['2026-12-20', '122.00']], $inv->installments->map(fn ($i) => [$i->due_date->toDateString(), (string) $i->amount])->all());
        $r->refresh();
        $this->assertSame(['2026-12-20', 1, $inv->id], [$r->next_date->toDateString(), $r->runs, $r->last_invoice_id]);
        $this->assertFalse(app(RecurringInvoiceService::class)->isDue($r));
        $this->expectException(ValidationException::class);
        app(RecurringInvoiceService::class)->issueNow($this->company, $this->owner, $r); // non ancora in scadenza
    }

    public function test_advance_keeps_anchor_day_and_quarter_period_label(): void
    {
        $s = app(RecurringInvoiceService::class);
        $d = $s->advance(CarbonImmutable::parse('2026-01-31'), 31, 1);
        $this->assertSame('2026-02-28', $d->toDateString());
        $this->assertSame('2026-03-31', $s->advance($d, 31, 1)->toDateString());
        $this->assertSame('2027-11-30', $s->advance(CarbonImmutable::parse('2026-11-30'), 30, 12)->toDateString());
        $r = $this->template(['frequency' => 'trimestrale']);
        $this->assertSame('novembre 2026 – gennaio 2027', $s->periodLabel($r));
    }

    public function test_auto_run_issues_due_auto_templates_only_and_records_failures(): void
    {
        $auto = $this->template(['auto_issue' => true, 'name' => 'Auto']);
        $manual = $this->template(['name' => 'Manuale']);
        $paused = $this->template(['auto_issue' => true, 'name' => 'Sospesa']);
        app(RecurringInvoiceService::class)->setActive($this->company, $this->owner, $paused, false);
        $this->assertSame(['issued' => 1, 'failed' => 0], app(RecurringInvoiceService::class)->runAuto());
        $this->assertSame(1, Invoice::where('source', 'emessa')->count());
        $this->assertSame(1, $auto->fresh()->runs);
        $this->assertSame(0, $manual->fresh()->runs);

        // Dati azienda mancanti → errore salvato, il modello resta in scadenza, nessun consumo di numero
        $this->company->update(['cap' => '']);
        $auto->fresh()->update(['next_date' => '2026-11-20']);
        $this->assertSame(['issued' => 0, 'failed' => 1], app(RecurringInvoiceService::class)->runAuto());
        $this->assertNotNull($auto->fresh()->last_error);
        $this->assertTrue(app(RecurringInvoiceService::class)->isDue($auto->fresh()));
        $this->assertSame(0, $this->artisan('semplicon:recurring-run')->run() === 1 ? 0 : 1);
    }

    public function test_end_date_stops_and_other_company_blocked(): void
    {
        $r = $this->template(['end_date' => '2026-11-25']);
        app(RecurringInvoiceService::class)->issueNow($this->company, $this->owner, $r);
        $this->assertFalse(app(RecurringInvoiceService::class)->isDue($r->fresh())); // la prossima scadenza 20.12. è dopo la fine
        $other = $this->customer();
        $this->actingAs($other)->post("/aziende/{$this->company->id}/fatture/ricorrenti/{$r->id}/emetti")->assertNotFound();
        $this->actingAs($this->owner)->delete("/aziende/{$this->company->id}/fatture/ricorrenti/{$r->id}");
        $this->assertDatabaseMissing('recurring_invoices', ['id' => $r->id]);
    }
}
