<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\IncomeStatementService;
use App\Domain\NonVatEntryService;
use App\Domain\RegistrationService;
use App\Domain\Scadenziario\Ledger;
use App\Domain\Scadenziario\PaymentService;
use App\Domain\VatProfileService;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\MoneyMovement;
use App\Models\User;
use App\Models\VatRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Conto economico: per competenza, per cassa e ai fini fiscali. */
class IncomeStatementTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2027-02-15 10:00:00');
        $this->owner = $this->customer();
        $this->company = app(CompanyService::class)->create($this->owner, ['name' => 'Alpha', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
    }

    private function regime(int $year, string $regime = 'semplificata', ?string $method = 'cassa', ?Company $c = null): void
    {
        $c ??= $this->company;
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($c, $this->owner, $year);
        $svc->updateDraft($c, $d, $this->owner, array_filter(['regime' => $regime, 'method' => $method, 'periodicity' => 'trimestrale']));
        $svc->confirm($c, $d->fresh(), $this->owner);
    }

    private function import(FatturaBuilder $b, ?Company $c = null): Invoice
    {
        $c ??= $this->company;
        $batch = ImportBatch::create(['company_id' => $c->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $c->id, 'filename' => 'f.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $b->xml(), $this->owner->id);

        return $c->invoices()->latest('id')->firstOrFail();
    }

    private function sale(string $no, string $date, string $taxable = '1000.00', string $tax = '220.00', array $rates = [], ?Company $c = null, string $type = 'TD01', ?string $ritenuta = null, string $vat = '01234567897'): Invoice
    {
        $b = FatturaBuilder::make()->supplier('IT'.$vat)->customer('IT22222222222', name: 'Cliente Rossi')
            ->body($no, $date, $type, summaries: [['rate' => $tax === '0.00' ? '0.00' : '22.00', 'taxable' => $taxable, 'tax' => $tax] + ($tax === '0.00' ? ['natura' => 'N2.2'] : [])]);
        if ($rates) {
            $b->payments($rates);
        }
        if ($ritenuta) {
            $b->ritenuta($ritenuta);
        }

        return $this->import($b, $c);
    }

    private function purchase(string $no, string $date, string $taxable = '1000.00', string $tax = '220.00', string $type = 'TD01'): Invoice
    {
        return $this->import(FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore Bianchi')->customer('IT01234567897')
            ->body($no, $date, $type, summaries: [['rate' => '22.00', 'taxable' => $taxable, 'tax' => $tax]]));
    }

    private function register(Invoice $inv, string $date, ?Company $c = null): VatRegistration
    {
        $c ??= $this->company;
        $inv = $inv->fresh();
        $buy = $inv->direction->value === 'acquisto';
        $code = $buy ? ($c->accounts()->where('code', '602023')->exists() ? '602023' : $c->accounts()->where('type', 'costo')->where('is_default', true)->value('code'))
            : $c->accounts()->where('type', 'ricavo')->where('is_default', true)->value('code');
        $acc = $c->accounts()->where('code', $code)->value('id');
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);

        return app(RegistrationService::class)->register($c, $inv, $this->owner, [
            'receipt_date' => $buy ? $inv->doc_date->toDateString() : null, 'registration_date' => $date, 'year' => $y, 'period' => intdiv($m - 1, 3) + 1,
            'lines' => $inv->vatSummaries->map(fn () => ['account_id' => $acc, 'deductible_pct' => '100'])->all(), 'acknowledged' => ['*'],
        ]);
    }

    private function pay(Invoice|array $targets, string $date, string $amount, array $extra = [], ?Company $c = null): MoneyMovement
    {
        $c ??= $this->company;
        $allocs = [];
        foreach (is_array($targets) ? $targets : [[$targets, $amount]] as [$inv, $amt]) {
            $inst = is_int($inv) ? $inv : $inv->installments()->orderBy('seq')->get()->first(fn ($i) => Ledger::installmentState($i)['residual'] > 0)->id;
            $allocs[] = ['installment_id' => $inst, 'amount' => $amt];
        }
        $first = is_array($targets) ? $targets[0][0] : $targets;
        $kind = $extra['kind'] ?? ((is_int($first) ? Installment::find($first)->invoice : $first)->fresh()->direction->value === 'vendita' ? 'incasso' : 'pagamento');

        return app(PaymentService::class)->record($c, $this->owner, ['kind' => $kind, 'movement_date' => $date, 'amount' => $amount, 'allocations' => $allocs, ...$extra]);
    }

    private function ce(int $year, string $view): array
    {
        return app(IncomeStatementService::class)->build($this->company, $year, $view);
    }

    /** Professionista: bilancio per registrazione, cassa e fiscalmente sempre per pagamento; pagamento parziale proporzionale, resto nell'anno successivo. */
    public function test_professionista_views_competenza_cassa_fiscale(): void
    {
        $this->regime(2026, 'professionisti', null);
        $this->regime(2027, 'professionisti', null);
        $s = $this->sale('S1', '2026-11-10');
        $this->register($s, '2026-11-10');
        $p = $this->purchase('P1', '2026-11-12', '500.00', '110.00');
        $this->register($p, '2026-11-12');
        $this->pay($s, '2026-12-10', '610.00');
        $this->pay($s, '2027-01-15', '610.00');

        $c = $this->ce(2026, 'competenza');
        $this->assertSame('1000.00', $c['totals']['ricavi']);
        $this->assertSame('500.00', $c['totals']['costi']);
        $this->assertSame('500.00', $c['totals']['risultato']);

        $k = $this->ce(2026, 'cassa');
        $this->assertSame('500.00', $k['totals']['ricavi']);
        $this->assertSame('0.00', $k['totals']['costi']);
        $this->assertSame('500.00', $this->ce(2027, 'cassa')['totals']['ricavi']);

        $f = $this->ce(2026, 'fiscale');
        $this->assertSame('cassa', $f['mode']);
        $this->assertSame('500.00', $f['totals']['ricavi']);
        $this->assertSame('500.00', $f['totals']['ricavi_fiscali']);
        $this->assertSame('0.00', $f['totals']['costi']);
        $this->assertSame('500.00', $this->ce(2027, 'fiscale')['totals']['ricavi']);
        $this->assertNotEmpty($c['ricavi'][0]['rows']);
    }

    /** Professionista forfettario: fiscalmente ugualmente per cassa (indicazione 21.11.2026). */
    public function test_professionista_forfettario_is_cash_basis(): void
    {
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2026);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'professionisti', 'periodicity' => 'trimestrale', 'forfettario_iva' => true]);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
        $s = $this->sale('S1', '2026-11-10');
        $this->register($s, '2026-11-10');
        $this->pay($s, '2026-12-10', '1220.00');
        $f = $this->ce(2026, 'fiscale');
        $this->assertSame('cassa', $f['mode']);
        $this->assertSame('1000.00', $f['totals']['ricavi']);
        $this->assertSame('0.00', $f['da_verificare']);
    }

    /** Semplificata per competenza: fiscalmente alla data di registrazione, anche senza pagamento. */
    public function test_semplificata_competenza_fiscal_uses_registration_date(): void
    {
        $this->regime(2026, 'semplificata', 'competenza');
        $s = $this->sale('S1', '2026-11-10');
        $this->register($s, '2026-11-10');
        $p = $this->purchase('P1', '2026-11-12', '500.00', '110.00');
        $this->register($p, '2026-11-12');
        $f = $this->ce(2026, 'fiscale');
        $this->assertSame('registrazione', $f['mode']);
        $this->assertSame('1000.00', $f['totals']['ricavi']);
        $this->assertSame('500.00', $f['totals']['costi']);
        $this->assertSame('0.00', $this->ce(2026, 'cassa')['totals']['ricavi']);
    }

    /** Fuori campo in tutte le viste; senza regime fiscalmente «da verificare»; pagina, CSV e PDF. */
    public function test_non_vat_entries_pending_and_downloads(): void
    {
        app(VatProfileService::class)->createDraft($this->company, $this->owner, 2026); // anno selezionabile, regime non confermato
        $acc = $this->company->accounts()->where('type', 'costo')->where('is_default', true)->firstOrFail();
        app(NonVatEntryService::class)->create($this->company, $this->owner, ['kind' => 'costo', 'entry_date' => '2026-11-10', 'account_id' => $acc->id, 'description' => 'Bollo', 'amount' => '16,00'], null);
        $this->assertSame('16.00', $this->ce(2026, 'competenza')['totals']['costi']);
        $this->assertSame('16.00', $this->ce(2026, 'cassa')['totals']['costi']);
        $f = $this->ce(2026, 'fiscale');
        $this->assertSame('da_verificare', $f['mode']);
        $this->assertSame('0.00', $f['totals']['costi']);
        $this->assertSame('16.00', $f['da_verificare']);

        $base = "/aziende/{$this->company->id}/conto-economico";
        $this->actingAs($this->owner)->get("{$base}?vista=competenza&anno=2026")->assertOk()
            ->assertInertia(fn ($p) => $p->component('IncomeStatement/Show')->where('data.view', 'competenza')->where('data.totals.costi', '16.00'));
        $csv = $this->actingAs($this->owner)->get("{$base}/csv?vista=competenza&anno=2026");
        $csv->assertOk();
        $this->assertStringContainsString('16,00', $csv->getContent());
        $this->actingAs($this->owner)->get("{$base}/pdf?vista=fiscale&anno=2026")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->customer())->get($base)->assertNotFound();
    }
}
