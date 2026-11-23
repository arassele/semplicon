<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\Liquidazioni\LipeService;
use App\Domain\Liquidazioni\SettlementCalculator;
use App\Domain\Liquidazioni\SettlementService;
use App\Domain\RegistrationService;
use App\Domain\VatProfileService;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\User;
use App\Models\VatRegistration;
use App\Models\VatSettlement;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Ticket 11: liquidazioni IVA, F24 e gestione del credito – casi di accettazione. */
class LipeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-12-20 10:00:00');
        $this->owner = $this->customer();
        $this->company = $this->newCompany('01234567897');
    }

    private function newCompany(string $vat): Company
    {
        return app(CompanyService::class)->create($this->owner, ['name' => 'Alpha '.$vat, 'tipologia' => 'societa', 'partita_iva' => $vat]);
    }

    private function regime(int $year, string $periodicity = 'mensile', ?string $qtype = null, ?Company $c = null, string $proRata = '100.00'): void
    {
        $c ??= $this->company;
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($c, $this->owner, $year);
        $svc->updateDraft($c, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => $periodicity, 'quarterly_type' => $qtype, 'pro_rata' => $proRata]);
        $svc->confirm($c, $d->fresh(), $this->owner);
    }

    /** Registrazione con imposta $tax (22 %) nel periodo indicato. */
    private function doc(string $dir, string $date, string $tax, int $period, string $type = 'TD01', string $pct = '100', ?Company $c = null, string $esig = 'I'): VatRegistration
    {
        $c ??= $this->company;
        $vat = substr($c->partita_iva, 0, 11);
        $taxable = Decimal::fromMinor(intdiv(Decimal::toMinor($tax) * 100, 22));
        $no = 'N'.(++$this->seq);
        $b = FatturaBuilder::make();
        $b = $dir === 'vendita' ? $b->supplier('IT'.$vat)->customer('IT22222222222', name: 'Cliente') : $b->supplier($type === 'TD17' ? null : 'IT11111111115', $type === 'TD17' ? null : null, 'Fornitore')->customer('IT'.$vat);
        if ($type === 'TD17') {
            $b = FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore estero')->customer('IT'.$vat);
        }
        $b->body($no, $date, $type, summaries: [['rate' => '22.00', 'taxable' => $taxable, 'tax' => $tax, 'esigibilita' => $esig]]);
        $batch = ImportBatch::create(['company_id' => $c->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $c->id, 'filename' => 'f.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $b->xml(), $this->owner->id);
        $inv = $c->invoices()->latest('id')->firstOrFail();
        $buy = $inv->direction->value === 'acquisto';
        $acc = $c->accounts()->where('type', $buy ? 'costo' : 'ricavo')->where('is_default', true)->value('id');

        return app(RegistrationService::class)->register($c, $inv, $this->owner, ['receipt_date' => $buy ? $date : null, 'registration_date' => $date,
            'year' => (int) substr($date, 0, 4), 'period' => $period, 'lines' => [['account_id' => $acc, 'deductible_pct' => $pct]], 'acknowledged' => ['*']]);
    }

    private function calc(int $year, int $period, ?Company $c = null): array
    {
        $calc = app(SettlementCalculator::class);
        $calc->forget();

        return $calc->compute($c ?? $this->company, $year, $period);
    }

    private function confirm(int $year, int $period, ?Company $c = null, ?string $req = null): VatSettlement
    {
        return app(SettlementService::class)->confirm($c ?? $this->company, $this->owner, $year, $period, $req);
    }

    private function eur(int $cents): string
    {
        return Decimal::fromMinor($cents);
    }

    public function test_monthly_quarter_builds_xml_with_modules_and_skips_empty_months(): void
    {
        $this->travelTo('2026-04-20 10:00:00');
        $this->regime(2026);
        $this->doc('vendita', '2026-01-10', '4400.00', 1);
        $this->doc('acquisto', '2026-01-12', '1100.00', 1);
        $this->doc('vendita', '2026-03-10', '220.00', 3);
        $this->confirm(2026, 1);
        $this->confirm(2026, 3);
        $b = app(LipeService::class)->build($this->company, 2026, 1);
        $this->assertTrue($b['ok'], implode(' ', $b['blocking']));
        $this->assertCount(2, $b['modules']);
        $this->assertSame(2000000, $b['modules'][0]['vp2']);
        $this->assertSame(500000, $b['modules'][0]['vp3']);
        $this->assertSame(440000, $b['modules'][0]['vp4']);
        $this->assertSame(330000, $b['modules'][0]['vp14_versare']);
        $this->assertSame('IT01234567897_LI_26101.xml', $b['filename']);
        $xml = app(LipeService::class)->xml($this->company, $b);
        $d = new \DOMDocument;
        $this->assertTrue($d->loadXML($xml));
        $x = new \DOMXPath($d);
        $x->registerNamespace('iv', LipeService::NS);
        $this->assertSame('IVP18', $x->evaluate('string(//iv:CodiceFornitura)'));
        $this->assertSame('01234567897', $x->evaluate('string(//iv:Frontespizio/iv:PartitaIVA)'));
        $this->assertSame('2026', $x->evaluate('string(//iv:AnnoImposta)'));
        $this->assertSame(2.0, $x->evaluate('count(//iv:Modulo)'));
        $this->assertSame('1', $x->evaluate('string(//iv:Modulo[1]/iv:Mese)'));
        $this->assertSame('3', $x->evaluate('string(//iv:Modulo[2]/iv:Mese)'));
        $this->assertSame('2', $x->evaluate('string(//iv:Modulo[2]/iv:NumeroModulo)'));
        $this->assertSame('20000,00', $x->evaluate('string(//iv:Modulo[1]/iv:TotaleOperazioniAttive)'));
        $this->assertSame('3300,00', $x->evaluate('string(//iv:Modulo[1]/iv:ImportoDaVersare)'));
    }

    public function test_unconfirmed_period_blocks_xml_and_review_blocks(): void
    {
        $this->travelTo('2026-04-20 10:00:00');
        $this->regime(2026);
        $this->doc('vendita', '2026-01-10', '4400.00', 1);
        $b = app(LipeService::class)->build($this->company, 2026, 1);
        $this->assertFalse($b['ok']);
        $this->assertStringContainsString('non confermata', $b['blocking'][0]);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/liquidazioni/lipe/2026/1/xml")->assertStatus(422);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/liquidazioni/lipe/2026/1/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->confirm(2026, 1);
        $this->doc('vendita', '2026-01-20', '220.00', 1);
        $b = app(LipeService::class)->build($this->company, 2026, 1);
        $this->assertFalse($b['ok']);
        $this->assertStringContainsString('riesaminare', $b['blocking'][0]);
    }

    public function test_quarterly_credit_module_and_download_route(): void
    {
        $this->travelTo('2026-07-20 10:00:00');
        $this->regime(2026, 'trimestrale', 'opzione');
        $this->doc('acquisto', '2026-05-10', '440.00', 2);
        $this->confirm(2026, 2);
        $b = app(LipeService::class)->build($this->company, 2026, 2);
        $this->assertTrue($b['ok']);
        $m = $b['modules'][0];
        $this->assertSame('Trimestre', $m['unit']);
        $this->assertSame(44000, $m['vp14_credito']);
        $this->assertSame(44000, $m['vp6_credito']);
        $r = $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/liquidazioni/lipe/2026/2/xml");
        $r->assertOk();
        $this->assertStringContainsString('<iv:Trimestre>2</iv:Trimestre>', $r->getContent());
        $this->assertStringContainsString('<iv:ImportoACredito>440,00</iv:ImportoACredito>', $r->getContent());
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/liquidazioni/lipe/2026/2/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('audit_events', ['action' => 'lipe.exported']);
    }

    public function test_deadlines_follow_the_calendar(): void
    {
        $this->assertSame('2027-05-31', LipeService::deadline(2027, 1));
        $this->assertSame('2026-09-16', LipeService::deadline(2026, 2));
        $this->assertSame('2026-11-30', LipeService::deadline(2026, 3));
        $this->assertSame('2027-03-01', LipeService::deadline(2026, 4));
    }
}
