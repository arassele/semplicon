<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\RegistrationService;
use App\Domain\Scadenziario\Ledger;
use App\Domain\Scadenziario\PaymentService;
use App\Domain\Scadenziario\ScadenziarioQuery;
use App\Domain\Scadenziario\ScheduleService;
use App\Domain\VatProfileService;
use App\Models\Company;
use App\Models\FiscalEntry;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\MoneyMovement;
use App\Models\User;
use App\Models\VatRegistration;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Scadenziario: casi di accettazione vincolanti 1–8 nonché bonifico cumulativo e pagamento in eccesso. */
class ScadenziarioTest extends TestCase
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

    /** @return array<int, string> anno => somma degli importi attribuiti fiscalmente */
    private function byYear(Invoice $inv, ?string $basis = null): array
    {
        $rows = FiscalEntry::where('invoice_id', $inv->id)->when($basis, fn ($q) => $q->where('basis', $basis))->get();
        $out = [];
        foreach ($rows->groupBy('year') as $y => $g) {
            $out[$y] = Decimal::fromMinor($g->sum(fn ($r) => Decimal::toMinor((string) $r->amount)));
        }
        ksort($out);

        return $out;
    }

    private function residualAt(Invoice $inv, ?string $asOf = null): string
    {
        return Decimal::fromMinor(Ledger::invoiceState($inv->fresh()->load('installments'), $asOf)['residual']);
    }

    public function test_installments_from_xml_and_reimport_without_duplicates(): void
    {
        $this->regime(2026);
        $b = FatturaBuilder::make()->supplier('IT01234567897')->customer('IT22222222222', name: 'Cliente Rossi')->body('V9', '2026-05-10')
            ->payments([['amount' => '61.00', 'due' => '2026-06-10', 'mode' => 'MP05'], ['amount' => '61.00', 'due' => '2026-07-10', 'mode' => 'MP05']], 'TP01');
        $inv = $this->import($b);
        $this->assertSame(['2026-06-10', '2026-07-10'], $inv->installments()->orderBy('seq')->get()->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->assertFalse($inv->fresh()->amount_check);
        $this->assertSame('MP05', $inv->installments()->first()->payment_mode);
        // modifica manuale, nuova importazione: nessuna duplicazione, la modifica resta
        $first = $inv->installments()->orderBy('seq')->first();
        app(ScheduleService::class)->update($this->company, $inv, $this->owner, [['id' => $first->id, 'due_date' => '2026-06-30', 'amount' => '61.00'], ['id' => $inv->installments()->orderBy('seq')->get()[1]->id, 'due_date' => '2026-07-10', 'amount' => '61.00']]);
        $this->import($b);
        $this->assertSame(1, $this->company->invoices()->count());
        $this->assertSame(2, $inv->installments()->count());
        $this->assertSame('2026-06-30', $inv->installments()->orderBy('seq')->first()->due_date->toDateString());

        // senza DatiPagamento: Scadenza da definire, mai scaduta
        $x = $this->sale('V10', '2026-01-10');
        $s = Ledger::installmentState($x->installments()->first());
        $this->assertSame('da_definire', $s['due']);
        $this->assertSame(122000, $s['residual']);
        // importo diverso → Importo da verificare
        $y = $this->sale('V11', '2026-01-10', rates: [['amount' => '100.00', 'due' => '2026-02-10', 'mode' => 'MP05']]);
        $this->assertTrue($y->fresh()->amount_check);
    }

    public function test_case1_december_invoice_two_payments_split_across_years(): void
    {
        $this->regime(2026);
        $this->regime(2027);
        $inv = $this->sale('V1', '2026-12-05');
        $this->register($inv, '2026-12-05');
        $this->pay($inv, '2026-12-20', '610.00');
        $this->pay($inv, '2027-01-15', '610.00');
        $this->assertSame([2026 => '500.00', 2027 => '500.00'], $this->byYear($inv, 'cassa'));
        $this->assertSame('610.00', $this->residualAt($inv, '2026-12-31'));
        $this->assertSame('0.00', $this->residualAt($inv));
        // Registro IVA invariato
        $this->assertSame(1, VatRegistration::where('invoice_id', $inv->id)->count());
    }

    public function test_case2_supplier_invoice_registered_december_paid_january_cash(): void
    {
        $this->regime(2026);
        $this->regime(2027);
        $inv = $this->purchase('F1', '2026-12-10');
        $this->register($inv, '2026-12-12');
        $this->pay($inv, '2027-01-20', '1220.00');
        $this->assertSame('1220.00', $this->residualAt($inv, '2026-12-31'));
        $this->assertSame([2027 => '1000.00'], $this->byYear($inv));
        $this->assertSame('costo', FiscalEntry::where('invoice_id', $inv->id)->value('nature'));
    }

    public function test_case3_presunzione_no_second_expense(): void
    {
        $this->regime(2026, method: 'competenza');
        $this->regime(2027, method: 'competenza');
        $inv = $this->purchase('F2', '2026-12-10');
        $this->register($inv, '2026-12-12');
        $this->pay($inv, '2027-01-20', '1220.00');
        $this->assertSame('1220.00', $this->residualAt($inv, '2026-12-31'));
        $this->assertSame([2026 => '1000.00', 2027 => '0.00'], $this->byYear($inv));
        $this->assertStringContainsString('già considerato alla registrazione', FiscalEntry::where('invoice_id', $inv->id)->where('year', 2027)->value('reason'));
    }

    public function test_case4_honorarnote_with_ritenuta(): void
    {
        $pro = app(CompanyService::class)->create($this->owner, ['name' => 'Studio', 'tipologia' => 'professionista', 'partita_iva' => '12345678903']);
        $this->regime(2026, 'professionisti', null, $pro);
        $inv = $this->sale('P1', '2026-06-10', '1000.00', '0.00', c: $pro, ritenuta: '200.00', vat: '12345678903');
        $this->assertSame('200.00', (string) $inv->fresh()->ritenuta);
        $this->assertSame('800.00', (string) $inv->installments()->first()->amount);
        $this->register($inv, '2026-06-10', $pro);
        $m = $this->pay($inv, '2026-06-30', '800.00', c: $pro);
        $this->assertSame('800.00', (string) $m->amount);
        $this->assertSame('0.00', $this->residualAt($inv));
        $this->assertSame([2026 => '1000.00'], $this->byYear($inv, 'cassa'));
    }

    public function test_case5_professionista_sostituto_pays_december_credited_january(): void
    {
        $pro = app(CompanyService::class)->create($this->owner, ['name' => 'Studio', 'tipologia' => 'professionista', 'partita_iva' => '12345678903']);
        $this->regime(2026, 'professionisti', null, $pro);
        $this->regime(2027, 'professionisti', null, $pro);
        $inv = $this->sale('P2', '2026-12-10', '1000.00', '0.00', c: $pro, ritenuta: '200.00', vat: '12345678903');
        $this->register($inv, '2026-12-10', $pro);
        $m = $this->pay($inv, '2027-01-04', '800.00', ['payer_date' => '2026-12-30'], $pro);
        $this->assertSame('2026-12-30', $m->payer_date->toDateString());
        $this->assertSame('2027-01-04', $m->movement_date->toDateString());
        $this->assertSame([2026 => '1000.00'], $this->byYear($inv, 'cassa'));
        $reason = FiscalEntry::where('invoice_id', $inv->id)->value('reason');
        $this->assertStringContainsString('30/12/2026', $reason);
        $this->assertStringContainsString('04/01/2027', $reason);
        // commercialmente ancora aperta al 31/12 (nota di credito in gennaio)
        $this->assertSame('800.00', $this->residualAt($inv, '2026-12-31'));
        // Data del sostituto solo per i professionisti
        $this->regime(2026);
        $x = $this->sale('V5', '2026-12-10');
        $this->expectException(ValidationException::class);
        $this->pay($x, '2027-01-04', '100.00', ['payer_date' => '2026-12-30']);
    }

    public function test_case6_three_rates_one_partial_only_due_rate_is_overdue(): void
    {
        $this->regime(2026);
        $this->regime(2027);
        $inv = $this->sale('V6', '2026-12-01', '300.00', '66.00', [['amount' => '122.00', 'due' => '2027-01-10', 'mode' => 'MP05'], ['amount' => '122.00', 'due' => '2027-03-10', 'mode' => 'MP05'], ['amount' => '122.00', 'due' => '2027-04-10', 'mode' => 'MP05']]);
        $rates = $inv->installments()->orderBy('seq')->get();
        $this->pay([[$rates[0]->id, '100.00']], '2027-01-12', '100.00');
        $st = $rates->map(fn ($r) => Ledger::installmentState($r->fresh()));
        $this->assertSame([2200, 12200, 12200], $st->pluck('residual')->all());
        $this->assertSame(['scaduta', 'a_scadere', 'a_scadere'], $st->pluck('due')->all());
        $this->assertSame(['parziale', 'aperta', 'aperta'], $st->pluck('paid')->all());
        $rows = app(ScadenziarioQuery::class)->installments($this->company, ['view' => 'da-incassare', 'overdue' => '1']);
        $this->assertCount(1, $rows);
        $this->assertSame('22.00', $rows[0]['residual']);
        $s = Ledger::invoiceState($inv->fresh()->load('installments'));
        $this->assertSame('scaduta', $s['due']);
        $inv->update(['payment_check' => false]);
        $this->assertSame('Parzialmente incassata', Ledger::paidLabel(Ledger::invoiceState($inv->fresh()->load('installments'))['paid'], true));
    }

    public function test_case7_credit_note_and_payment_storno(): void
    {
        $this->regime(2026);
        $inv = $this->sale('V7', '2026-03-01');
        $nc = $this->sale('NC1', '2026-03-20', '200.00', '44.00', type: 'TD04');
        $this->register($inv, '2026-03-01');
        $this->register($nc, '2026-03-20');
        app(PaymentService::class)->compensate($this->company, $this->owner, $inv->installments()->first()->id, $nc->installments()->first()->id, '244.00', '2026-03-25');
        $this->assertSame('976.00', $this->residualAt($inv));
        $this->assertSame('0.00', $this->residualAt($nc));
        $this->assertSame(0, MoneyMovement::count()); // nessun incasso bancario fittizio
        // Imposta: +200 fattura, −200 nota di credito (saldo 0 nell'anno della compensazione)
        $this->assertSame([2026 => '200.00'], $this->byYear($inv));
        $this->assertSame([2026 => '-200.00'], $this->byYear($nc));

        $m = $this->pay($inv, '2026-04-10', '976.00');
        $this->assertSame([2026 => '1000.00'], $this->byYear($inv));
        app(PaymentService::class)->reverse($this->company, $m, $this->owner, 'Bonifico registrato due volte');
        $this->assertSame('976.00', $this->residualAt($inv));
        $this->assertSame([2026 => '200.00'], $this->byYear($inv));
        $this->assertSame('stornata', $m->fresh()->status);
    }

    public function test_case8_idempotency_and_tenancy(): void
    {
        $this->regime(2026);
        $inv = $this->sale('V8', '2026-03-01');
        $d = ['request_id' => 'req-1'];
        $a = $this->pay($inv, '2026-03-10', '500.00', $d);
        $b = $this->pay($inv, '2026-03-10', '500.00', $d);
        $this->assertSame($a->id, $b->id);
        $this->assertTrue($b->replayed);
        $this->assertSame(1, MoneyMovement::count());
        $this->assertSame('720.00', $this->residualAt($inv));

        // altra azienda dello stesso cliente: nessuna compensazione tra aziende
        $other = app(CompanyService::class)->create($this->owner, ['name' => 'Beta', 'tipologia' => 'societa', 'partita_iva' => '09876543217']);
        $this->regime(2026, c: $other);
        $foreign = $this->sale('V8', '2026-03-01', c: $other, vat: '09876543217');
        try {
            $this->pay([[$inv, '100.00'], [$foreign->installments()->first()->id, '100.00']], '2026-03-11', '200.00');
            $this->fail('attribuzione tra aziende diverse attesa come rifiutata');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations.1.installment_id', $e->errors());
        }
        $this->assertSame(1, MoneyMovement::count());
    }

    public function test_bulk_transfer_and_overpayment(): void
    {
        $this->regime(2026);
        $a = $this->sale('V20', '2026-03-01', '491.80', '108.20'); // 600
        $b = $this->sale('V21', '2026-03-02', '409.84', '90.16');  // 500
        $m = $this->pay([[$a, '600.00'], [$b, '400.00']], '2026-03-30', '1000.00');
        $this->assertSame(1, MoneyMovement::count());
        $this->assertSame('0.00', $this->residualAt($a));
        $this->assertSame('100.00', $this->residualAt($b));
        $this->assertSame(2, $m->allocations()->count());
        // Pagamento in eccesso: il resto resta «Importo da abbinare»
        $c = $this->sale('V22', '2026-03-05', '100.00', '22.00');
        $over = $this->pay([[$c, '122.00']], '2026-03-31', '150.00');
        $this->assertSame(2800, PaymentService::unallocatedCents($over));
        // Attribuzione oltre il residuo rifiutata
        $this->expectException(ValidationException::class);
        $this->pay([[$b, '150.00']], '2026-03-31', '150.00');
    }

    public function test_unconfirmed_profile_marks_tax_effect_to_verify(): void
    {
        $this->regime(2026);
        $inv = $this->sale('V30', '2026-12-01');
        $this->register($inv, '2026-12-01');
        $this->pay($inv, '2027-01-10', '1220.00'); // 2027 senza profilo confermato
        $this->assertSame([2027 => '1000.00'], $this->byYear($inv, 'da_verificare'));
        $this->regime(2027);
        $this->assertSame([2027 => '1000.00'], $this->byYear($inv, 'cassa'));
    }

    public function test_http_views_csv_and_cutoff(): void
    {
        $this->regime(2026);
        $this->regime(2027);
        $inv = $this->sale('V40', '2026-12-05');
        $this->register($inv, '2026-12-05');
        $this->pay($inv, '2027-01-15', '1220.00');
        $base = "/aziende/{$this->company->id}";
        $this->actingAs($this->owner);

        $this->get("{$base}/scadenziario/da-incassare")->assertOk()->assertInertia(fn ($p) => $p->component('Scadenziario/Index')->has('rows', 0));
        $this->get("{$base}/scadenziario/da-incassare?al_giorno=2026-12-31")->assertInertia(fn ($p) => $p->has('rows', 1)->where('rows.0.residual', '1220.00')->where('totals.residual', '1220.00'));
        $this->get("{$base}/scadenziario/da-incassare?stato=saldata&periodo=pagamento&dal=2027-01-01&al=2027-01-31")->assertInertia(fn ($p) => $p->has('rows', 1)->where('rows.0.paid', 'saldata'));
        $this->get("{$base}/scadenziario/incassi-e-pagamenti")->assertOk()->assertInertia(fn ($p) => $p->component('Scadenziario/Movements')->has('rows', 1));
        $this->get("{$base}/scadenziario/riepilogo-fiscale?anno=2026")->assertOk()->assertInertia(fn ($p) => $p->where('openAtYearEnd.sale', '1220.00')->where('data.totals.ricavo', '0.00'));
        $this->get("{$base}/scadenziario/riepilogo-fiscale?anno=2027")->assertInertia(fn ($p) => $p->where('data.totals.ricavo', '1000.00')->has('data.documents', 1));
        $this->get("{$base}/fatture/{$inv->id}")->assertInertia(fn ($p) => $p->where('schedule.state.paid', 'saldata')->has('schedule.history', 1)->where('schedule.fiscal.0.year', 2027));

        $csv = $this->get("{$base}/scadenziario/da-incassare/csv?al_giorno=2026-12-31")->assertOk();
        $this->assertStringContainsString('V40', $csv->getContent());
        $this->assertStringContainsString('1220,00', $csv->getContent());
        $this->assertStringContainsString('incassi', $this->get("{$base}/scadenziario/incassi-e-pagamenti/csv")->assertOk()->headers->get('content-disposition'));
    }

    public function test_http_payment_double_submit_and_tenancy(): void
    {
        $this->regime(2026);
        $inv = $this->sale('V41', '2026-03-01');
        $base = "/aziende/{$this->company->id}";
        $payload = ['kind' => 'incasso', 'movement_date' => '2026-03-10', 'amount' => '1.220,00', 'request_id' => 'abc-1',
            'allocations' => [['installment_id' => $inv->installments()->first()->id, 'amount' => '1220,00']]];
        $payload['amount'] = '1220,00';
        $this->actingAs($this->owner)->post("{$base}/scadenziario/movimenti", $payload)->assertRedirect()->assertSessionHas('success');
        $this->post("{$base}/scadenziario/movimenti", $payload)->assertSessionHas('success', fn ($m) => str_contains($m, 'nessun duplicato'));
        $this->assertSame(1, MoneyMovement::count());
        $m = MoneyMovement::first();

        // Altra cliente: nessun accesso ad azienda, movimento o attribuzione
        $stranger = $this->customer('Altra');
        $this->actingAs($stranger)->get("{$base}/scadenziario/da-incassare")->assertNotFound();
        $this->post("{$base}/scadenziario/movimenti/{$m->id}/storna", ['reason' => 'x'])->assertNotFound();
        $own = $this->companyFor($stranger);
        $this->post("/aziende/{$own->id}/scadenziario/movimenti/{$m->id}/storna", ['reason' => 'x'])->assertNotFound();
        $this->post("/aziende/{$own->id}/scadenziario/movimenti", $payload + ['request_id' => 'z'])->assertSessionHasErrors('allocations.0.installment_id');
        $this->assertSame('valida', $m->fresh()->status);

        // Storno con motivazione via HTTP
        $this->actingAs($this->owner)->post("{$base}/scadenziario/movimenti/{$m->id}/storna", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("{$base}/scadenziario/movimenti/{$m->id}/storna", ['reason' => 'Errore'])->assertSessionHas('success');
        $this->assertSame('1220.00', $this->residualAt($inv));
        // Una fattura con pagamenti validi non si può eliminare
        $this->pay($inv, '2026-03-11', '100.00');
        $this->delete("{$base}/fatture/{$inv->id}")->assertSessionHasErrors('invoice');
    }

    public function test_installment_edit_protects_paid_amounts_and_migration_flag(): void
    {
        $this->regime(2026);
        $inv = $this->sale('V42', '2026-03-01');
        $this->pay($inv, '2026-03-10', '500.00');
        $id = $inv->installments()->first()->id;
        $this->actingAs($this->owner)->put("/aziende/{$this->company->id}/fatture/{$inv->id}/scadenze", ['rows' => [['id' => $id, 'due_date' => '2026-04-01', 'amount' => '400,00']]])
            ->assertSessionHasErrors('installments.0.amount');
        $this->put("/aziende/{$this->company->id}/fatture/{$inv->id}/scadenze", ['rows' => [['id' => $id, 'due_date' => '2026-04-01', 'amount' => '610,00'], ['due_date' => '2026-05-01', 'amount' => '610,00']]])
            ->assertSessionHas('success');
        $this->assertSame(2, $inv->installments()->count());
        $this->assertFalse($inv->fresh()->amount_check);
        $this->post("/aziende/{$this->company->id}/fatture/{$inv->id}/stato-pagamento")->assertSessionHas('success');
        $this->assertFalse($inv->fresh()->payment_check);
    }
}
