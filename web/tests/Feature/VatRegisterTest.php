<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\RegistrationService;
use App\Domain\VatProfileService;
use App\Domain\VatRegisterService;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRegistration;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** I04: Registri IVA. Accettazione secondo piano: i totali coincidono con le registrazioni, ogni riga porta al documento. */
class VatRegisterTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-18 10:00:00');
        $this->owner = $this->customer();
        $this->company = app(CompanyService::class)->create($this->owner, ['name' => 'Alpha', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2026);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
    }

    private function import(string $xml): Invoice
    {
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => 'f.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $xml, $this->owner->id);

        return $this->company->invoices()->latest('id')->firstOrFail();
    }

    private function acc(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function purchase(string $n, string $date, array $sums, string $type = 'TD01', array $pct = []): VatRegistration
    {
        $inv = $this->import(FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore Uno')->customer('IT01234567897')->body($n, $date, $type, summaries: $sums)->xml());
        $month = (int) substr($date, 5, 2);

        return app(RegistrationService::class)->register($this->company, $inv, $this->owner, [
            'receipt_date' => $date, 'registration_date' => $date, 'year' => 2026, 'period' => $month,
            'lines' => array_map(fn ($i) => ['account_id' => $this->acc('602023'), 'deductible_pct' => $pct[$i] ?? '100'], array_keys($sums)),
            'acknowledged' => app(RegistrationService::class)->checks($this->company, $inv)['acknowledge'],
        ]);
    }

    private function sale(string $n, string $date, array $sums): VatRegistration
    {
        $inv = $this->import(FatturaBuilder::make()->supplier('IT01234567897')->customer('IT22222222222', name: 'Cliente Due')->body($n, $date, summaries: $sums)->xml());

        return app(RegistrationService::class)->register($this->company, $inv, $this->owner, [
            'registration_date' => $date, 'year' => 2026, 'period' => (int) substr($date, 5, 2),
            'lines' => array_map(fn () => ['account_id' => $this->acc('701003')], $sums), 'acknowledged' => [],
        ]);
    }

    public function test_purchase_register_totals_equal_the_sum_of_registrations(): void
    {
        $this->purchase('A1', '2026-03-02', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00'], ['rate' => '10.00', 'taxable' => '50.00', 'tax' => '5.00']]);
        $this->purchase('A2', '2026-03-05', [['rate' => '22.00', 'taxable' => '200.00', 'tax' => '44.00']], pct: ['40']);
        $this->purchase('A3', '2026-03-09', [['rate' => '0.00', 'taxable' => '7.50', 'tax' => '0.00', 'natura' => 'N2.2']]);

        $data = app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, 3);

        $this->assertSame([1, 2, 3], array_column($data['entries'], 'protocol'));
        $this->assertSame(['357.50', '71.00', '44.60'], array_values($data['sum']), 'Imponibile, Imposta, Detraibile');
        // Ricalcolato in modo indipendente dalle righe di registrazione nel database.
        $lines = VatRegistration::with('lines')->get()->flatMap->lines;
        $this->assertSame(Decimal::fromMinor($lines->sum(fn ($l) => Decimal::toMinor((string) $l->taxable))), $data['sum']['taxable']);
        $this->assertSame(Decimal::fromMinor($lines->sum(fn ($l) => Decimal::toMinor((string) $l->deductible_tax))), $data['sum']['deductible']);

        $byLabel = collect($data['totals'])->keyBy('label');
        $this->assertSame(['300.00', '66.00', '39.60'], [$byLabel['22,00%']['taxable'], $byLabel['22,00%']['tax'], $byLabel['22,00%']['deductible']]);
        $this->assertSame('7.50', collect($data['totals'])->firstWhere('natura', 'N2.2')['taxable']);
    }

    public function test_period_filter_and_whole_year(): void
    {
        $this->purchase('A1', '2026-02-02', [['rate' => '22.00', 'taxable' => '10.00', 'tax' => '2.20']]);
        $this->purchase('A2', '2026-03-02', [['rate' => '22.00', 'taxable' => '20.00', 'tax' => '4.40']]);
        $svc = app(VatRegisterService::class);

        $this->assertCount(1, $svc->build($this->company, 'acquisti', 2026, 2)['entries']);
        $this->assertSame('30.00', $svc->build($this->company, 'acquisti', 2026)['sum']['taxable']);
        $this->assertCount(0, $svc->build($this->company, 'acquisti', 2025)['entries']);
    }

    public function test_reversal_nets_to_zero_in_its_own_period(): void
    {
        $reg = $this->purchase('A1', '2026-03-02', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']]);
        app(RegistrationService::class)->reverse($this->company, $reg, $this->owner, 'Errore', '2026-04-10');
        $svc = app(VatRegisterService::class);

        $march = $svc->build($this->company, 'acquisti', 2026, 3);
        $april = $svc->build($this->company, 'acquisti', 2026, 4);
        $this->assertSame('stornata', $march['entries'][0]['status']);
        $this->assertSame('22.00', $march['sum']['tax'], 'l’originale resta a marzo');
        $this->assertSame(['storno', 1], [$april['entries'][0]['status'], $april['entries'][0]['reverses_protocol']]);
        $this->assertSame('-22.00', $april['sum']['tax']);
        $this->assertSame('0.00', $svc->build($this->company, 'acquisti', 2026)['sum']['tax'], 'anno netto zero');
    }

    public function test_credit_note_is_negative(): void
    {
        $this->purchase('A1', '2026-03-02', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']]);
        $this->purchase('NC1', '2026-03-05', [['rate' => '22.00', 'taxable' => '30.00', 'tax' => '6.60']], 'TD04');

        $this->assertSame(['70.00', '15.40', '15.40'], array_values(app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, 3)['sum']));
    }

    public function test_sales_register_contains_sales_and_reverse_charge_integrations(): void
    {
        $this->sale('V1', '2026-03-03', [['rate' => '22.00', 'taxable' => '500.00', 'tax' => '110.00']]);
        $this->purchase('A1', '2026-03-04', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']]);
        $this->purchase('INT1', '2026-03-06', [['rate' => '22.00', 'taxable' => '1000.00', 'tax' => '220.00']], 'TD17');

        $sales = app(VatRegisterService::class)->build($this->company, 'vendite', 2026, 3);
        $this->assertSame(['V1', 'INT1'], array_column($sales['entries'], 'number'));
        $this->assertTrue($sales['entries'][1]['integration']);
        $this->assertSame('A2/2026', $sales['entries'][1]['protocol_label']);
        $this->assertSame('330.00', $sales['sum']['tax'], 'IVA a debito inclusa l’integrazione');
        $this->assertSame('0.00', $sales['sum']['deductible']);

        $purchases = app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, 3);
        $this->assertSame(['A1', 'INT1'], array_column($purchases['entries'], 'number'));
        $this->assertSame('242.00', $purchases['sum']['deductible']);
    }

    public function test_page_rows_link_to_invoices(): void
    {
        $reg = $this->purchase('A1', '2026-03-02', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']]);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/registri/acquisti?anno=2026&periodo=3")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Registers/Index')->where('data.entries.0.invoice_id', $reg->invoice_id)
                ->where('periodicity', 'mensile')->has('periods', 12)->where('data.sum.tax', '22.00'));
    }

    public function test_csv_export_for_excel(): void
    {
        $this->purchase('=HYPERLINK("x")', '2026-03-02', [['rate' => '22.00', 'taxable' => '1234.50', 'tax' => '271.59']]);
        $response = $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/registri/acquisti/csv?anno=2026&periodo=3");
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF".'Registro;Protocollo;', $csv);
        $this->assertStringContainsString(';1234,50;271,59;100,00;271,59', $csv);
        $this->assertStringContainsString(";\"'=HYPERLINK(\"\"x\"\")\";", $csv, 'formula neutralizzata');
        $this->assertDatabaseHas('audit_events', ['action' => 'register.exported']);
    }

    public function test_pdf_export(): void
    {
        $this->sale('V1', '2026-03-03', [['rate' => '22.00', 'taxable' => '500.00', 'tax' => '110.00']]);
        $response = $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/registri/vendite/pdf?anno=2026");
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_other_customer_cannot_see_registers(): void
    {
        $this->purchase('A1', '2026-03-02', [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']]);
        $stranger = $this->customer('Estraneo');
        foreach (['', '/csv', '/pdf'] as $suffix) {
            $this->actingAs($stranger)->get("/aziende/{$this->company->id}/registri/acquisti{$suffix}")->assertNotFound();
        }
        $own = $this->companyFor($stranger);
        $this->actingAs($stranger)->get("/aziende/{$own->id}/registri/acquisti?anno=2026")
            ->assertInertia(fn ($p) => $p->has('data.entries', 0));
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/registri/altro")->assertNotFound();
    }
}
