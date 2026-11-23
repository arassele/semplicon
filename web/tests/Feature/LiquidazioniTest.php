<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\Liquidazioni\CreditService;
use App\Domain\Liquidazioni\F24Service;
use App\Domain\Liquidazioni\Rules;
use App\Domain\Liquidazioni\SettlementCalculator;
use App\Domain\Liquidazioni\SettlementService;
use App\Domain\RegistrationService;
use App\Domain\VatProfileService;
use App\Models\Company;
use App\Models\F24Draft;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\User;
use App\Models\VatClaim;
use App\Models\VatCredit;
use App\Models\VatCreditMovement;
use App\Models\VatProfile;
use App\Models\VatRegistration;
use App\Models\VatSettlement;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Ticket 11: liquidazioni IVA, F24 e gestione del credito – casi di accettazione. */
class LiquidazioniTest extends TestCase
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

    public function test_january_debit_with_previous_credit_and_f24_6001(): void
    {
        $this->travelTo('2026-02-05 10:00:00');
        $this->regime(2025);
        $this->regime(2026);
        $this->doc('acquisto', '2025-12-10', '300.00', 12);
        $dec = $this->confirm(2025, 12);
        $this->assertSame('credito', $dec->result);
        $credit = VatCredit::where('settlement_id', $dec->id)->firstOrFail();
        $this->assertSame('annuale', $credit->kind);
        app(CreditService::class)->allocate($this->company, $this->owner, [['credit_id' => $credit->id, 'riporto' => '300.00']]);

        $this->doc('vendita', '2026-01-10', '4400.00', 1);
        $this->doc('acquisto', '2026-01-12', '1100.00', 1);
        $d = $this->calc(2026, 1);
        $this->assertSame('4400.00', $this->eur($d['figures']['debit']));
        $this->assertSame('1100.00', $this->eur($d['figures']['deductible']));
        $this->assertSame(30000, $d['figures']['credit_previous'] + $d['figures']['credit_annual']);
        $this->assertSame('3000.00', $this->eur($d['figures']['due']));
        $s = $this->confirm(2026, 1);
        $f = app(F24Service::class)->generate($this->company, $this->owner, $s);
        $this->assertSame('6001', $f->lines[0]['code']);
        $this->assertSame(2026, $f->lines[0]['year']);
        $this->assertSame('3000.00', (string) $f->balance);
        $this->assertSame('2026-02-16', $f->due_date->toDateString());
        $this->assertSame('utilizzata', VatCreditMovement::where('credit_id', $credit->id)->value('status'));
    }

    public function test_rules_exist_for_2027_and_2028_but_not_beyond(): void
    {
        $this->travelTo('2027-02-10 10:00:00');
        $this->regime(2027);
        $this->doc('vendita', '2027-01-10', '2200.00', 1);
        $d = $this->calc(2027, 1);
        $this->assertTrue($d['ok']);
        $this->assertSame('LIQ-2027.1', $d['rule_version']);
        $this->assertSame('2200.00', $this->eur($d['figures']['due']));
        $this->assertSame('2027-02-16', $d['due_date']);
        $this->assertSame('LIQ-2028.1', Rules::for(2028)['version']);
        $this->assertNull(Rules::for(2029));
    }

    public function test_third_quarter_option_interest_and_6033(): void
    {
        $this->regime(2026, 'trimestrale', 'opzione');
        $this->doc('vendita', '2026-08-10', '2000.00', 3);
        $d = $this->calc(2026, 3);
        $this->assertSame('2000.00', $this->eur($d['figures']['tax']));
        $this->assertSame('20.00', $this->eur($d['figures']['interest']));
        $this->assertSame('2020.00', $this->eur($d['figures']['due']));
        $s = $this->confirm(2026, 3);
        $f = app(F24Service::class)->generate($this->company, $this->owner, $s);
        $this->assertSame('6033', $f->lines[0]['code']);
        $this->assertSame('2020.00', (string) $f->total_debit);
        $this->assertSame('2026-11-16', $f->due_date->toDateString());
        // Trimestrali speciali: nessun interesse, Q4 con 6034
        $sp = $this->newCompany('09876543217');
        $this->regime(2026, 'trimestrale', 'speciale', $sp);
        $this->doc('vendita', '2026-08-10', '2000.00', 3, c: $sp);
        $this->assertSame(0, $this->calc(2026, 3, $sp)['figures']['interest']);
        $this->assertSame('6034', $this->calc(2026, 4, $sp)['code']['code']);
        $this->assertSame('6099', $this->calc(2026, 4)['code']['code']);
    }

    public function test_small_debts_deferral_threshold_and_year_end(): void
    {
        $this->regime(2026);
        $this->doc('vendita', '2026-03-10', '80.00', 3);
        $mar = $this->confirm(2026, 3);
        $this->assertSame('80.00', (string) $mar->deferred);
        $this->assertSame('0.00', (string) $mar->amount_due);
        $this->assertNull($mar->due_date);
        $this->doc('vendita', '2026-04-10', '70.00', 4);
        $apr = $this->calc(2026, 4);
        $this->assertSame(8000, $apr['figures']['deferred_in']);
        $this->assertSame('150.00', $this->eur($apr['figures']['due']));
        $this->confirm(2026, 4);
        // Esattamente 100 € vengono rinviati (non superiore a 100)
        $this->doc('vendita', '2026-05-10', '100.00', 5);
        $this->assertSame(10000, $this->calc(2026, 5)['figures']['deferred_out']);
        // Novembre: scadenza successiva dopo il 16 dicembre → nessun rinvio
        $this->doc('vendita', '2026-11-10', '80.00', 11);
        $nov = $this->calc(2026, 11);
        $this->assertFalse($nov['deferral_allowed']);
        $this->assertSame(0, $nov['figures']['deferred_out']);
        // i 100 € di maggio proseguono da giugno a ottobre e sono da pagare al più tardi con novembre (16 dicembre)
        $this->assertSame(10000, $nov['figures']['deferred_in']);
        $this->assertSame('180.00', $this->eur($nov['figures']['due']));
        $this->assertSame('2026-12-16', $nov['due_date']);
        $this->assertTrue($this->calc(2026, 10)['deferral_allowed']);
    }

    public function test_monthly_credit_carried_to_march_without_tr(): void
    {
        $this->regime(2026);
        $this->doc('acquisto', '2026-02-10', '1400.00', 2);
        $feb = $this->confirm(2026, 2);
        $c = VatCredit::where('settlement_id', $feb->id)->firstOrFail();
        $this->assertSame('periodico', $c->kind);
        $svc = app(CreditService::class);
        $this->assertSame([2026, 3], $svc->nextFor($this->company, $c));
        $svc->allocate($this->company, $this->owner, [['credit_id' => $c->id, 'riporto' => '1400.00']]);
        $this->assertSame(140000, $this->calc(2026, 3)['figures']['credit_previous']);
        $this->assertFalse($svc->availability($c, 'rimborso', 100)['ok']);
        $this->assertFalse($svc->availability($c, 'compensazione', 100)['ok']);
        $this->assertFalse($svc->trCheck($this->company, 2026, 1)['eligible']);
        $this->expectException(ValidationException::class);
        $svc->allocate($this->company, $this->owner, [['credit_id' => $c->id, 'rimborso' => '100.00']], ['presupposto' => 'a']);
    }

    public function test_tr_threshold_excludes_previous_carry_and_is_strict(): void
    {
        $this->travelTo('2026-07-10 10:00:00');
        $this->regime(2026, 'trimestrale', 'opzione');
        $this->doc('acquisto', '2026-02-10', '1000.00', 1);
        $q1 = $this->confirm(2026, 1);
        $svc = app(CreditService::class);
        $svc->allocate($this->company, $this->owner, [['credit_id' => VatCredit::where('settlement_id', $q1->id)->value('id'), 'riporto' => '1000.00']]);
        $this->doc('acquisto', '2026-05-10', '2400.00', 2);
        $q2 = $this->confirm(2026, 2);
        $this->assertSame('3400.00', (string) $q2->credit);
        $chk = $svc->trCheck($this->company, 2026, 2);
        $this->assertSame(240000, $chk['matured']);
        $this->assertFalse($chk['eligible']);

        foreach (['2582.28' => false, '2582.29' => true] as $amount => $eligible) {
            $co = $this->newCompany($eligible ? '11223344556' : '65544332210');
            $this->regime(2026, 'trimestrale', 'opzione', $co);
            $this->doc('acquisto', '2026-05-10', $amount, 2, c: $co);
            $this->confirm(2026, 2, $co);
            $this->assertSame($eligible, $svc->trCheck($co, 2026, 2)['eligible'], $amount);
        }
    }

    public function test_mixed_destination_of_annual_credit(): void
    {
        $this->travelTo('2026-04-10 10:00:00');
        $this->regime(2026);
        $svc = app(CreditService::class);
        $c = $svc->registerAnnual($this->company, $this->owner, ['year' => 2025, 'amount' => '10000.00', 'filed_at' => '2026-03-02', 'visto' => true]);
        $svc->allocate($this->company, $this->owner, [['credit_id' => $c->id, 'riporto' => '4000', 'compensazione' => '3000', 'rimborso' => '3000']], ['target_year' => 2026, 'target_period' => 4, 'presupposto' => 'a']);
        $this->assertSame(0, $c->fresh()->residualCents());
        $this->assertSame(400000, $this->calc(2026, 4)['figures']['credit_annual']);
        $this->assertSame(1, VatClaim::where('credit_id', $c->id)->where('status', 'bozza')->count());
        $this->assertTrue($svc->availability($c->fresh(), 'compensazione', 0, '2026-04-16')['ok']);
        // Non è possibile più del residuo
        $this->expectException(ValidationException::class);
        $svc->allocate($this->company, $this->owner, [['credit_id' => $c->id, 'riporto' => '1']]);
    }

    public function test_f24_balance_zero_keeps_deadline_open(): void
    {
        $this->travelTo('2026-02-05 10:00:00');
        $this->regime(2026);
        $svc = app(CreditService::class);
        $c = $svc->registerAnnual($this->company, $this->owner, ['year' => 2025, 'amount' => '3000.00']);
        $this->doc('vendita', '2026-01-10', '3000.00', 1);
        $s = $this->confirm(2026, 1);
        $f = app(F24Service::class)->generate($this->company, $this->owner, $s, [['credit_id' => $c->id, 'amount' => '3000.00']]);
        $this->assertSame('0.00', (string) $f->balance);
        $this->assertSame('6099', $f->lines[1]['code']);
        $this->assertSame(2025, $f->lines[1]['year']);
        $this->assertSame('bozza', $f->status);
        $this->assertSame('2026-02-16', $f->due_date->toDateString());
        $this->assertSame(0, $c->fresh()->residualCents());
        $this->assertSame('prenotata', VatCreditMovement::where('f24_id', $f->id)->value('status'));
        app(F24Service::class)->present($this->company, $this->owner, $f, '2026-02-05', 'PROT-1');
        $this->assertSame('utilizzata', VatCreditMovement::where('f24_id', $f->id)->value('status'));
        // Addebito solo con saldo > 0
        $this->expectException(ValidationException::class);
        app(F24Service::class)->confirmDebit($this->company, $this->owner, $f->fresh(), '2026-02-16');
    }

    public function test_correction_identifies_affected_periods_and_keeps_originals(): void
    {
        $this->travelTo('2026-02-25 10:00:00');
        $this->regime(2026);
        $this->doc('acquisto', '2026-02-10', '1000.00', 2);
        $b = $this->doc('acquisto', '2026-02-11', '400.00', 2);
        $feb = $this->confirm(2026, 2);
        $c = VatCredit::where('settlement_id', $feb->id)->firstOrFail();
        app(CreditService::class)->allocate($this->company, $this->owner, [['credit_id' => $c->id, 'riporto' => '1400.00']]);
        app(RegistrationService::class)->reverse($this->company, $b, $this->owner, 'Fattura errata', '2026-02-20');
        $feb->refresh();
        $this->assertTrue($feb->review_required);
        $cmp = app(SettlementService::class)->compare($this->company, $feb);
        $this->assertSame('-400.00', $cmp['credit_diff']);
        $this->assertTrue(collect($cmp['effects'])->contains(fn ($e) => $e['type'] === 'periodo' && ($e['link']['period'] ?? null) === 3));
        // Nuova versione solo se le destinazioni non superano il nuovo credito
        try {
            $this->confirm(2026, 2);
            $this->fail('rifiuto atteso');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('già destinati', $e->errors()['settlement'][0]);
        }
        $m = VatCreditMovement::where('credit_id', $c->id)->first();
        app(CreditService::class)->release($this->company, $this->owner, $m, 'Credito ridotto dallo storno');
        $v2 = $this->confirm(2026, 2);
        $this->assertSame(2, $v2->version);
        $this->assertSame('1000.00', (string) $v2->credit);
        $this->assertSame('sostituita', $feb->fresh()->status);
        $this->assertSame('1400.00', (string) $feb->fresh()->credit); // l'originale resta conservato
    }

    public function test_concurrency_double_confirm_and_competing_f24(): void
    {
        $this->travelTo('2026-03-05 10:00:00');
        $this->regime(2026);
        $c = app(CreditService::class)->registerAnnual($this->company, $this->owner, ['year' => 2025, 'amount' => '3000.00']);
        $this->doc('vendita', '2026-01-10', '3000.00', 1);
        $a = $this->confirm(2026, 1, req: 'r1');
        $b = $this->confirm(2026, 1, req: 'r1');
        $x = $this->confirm(2026, 1);
        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->id, $x->id);
        $this->assertSame(1, VatSettlement::count());
        $this->doc('vendita', '2026-02-10', '3000.00', 2);
        $feb = $this->confirm(2026, 2);
        app(F24Service::class)->generate($this->company, $this->owner, $a, [['credit_id' => $c->id, 'amount' => '3000.00']]);
        try {
            app(F24Service::class)->generate($this->company, $this->owner, $feb, [['credit_id' => $c->id, 'amount' => '3000.00']]);
            $this->fail('seconda prenotazione della stessa quota attesa come rifiutata');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('compensations.0', $e->errors());
        }
        $this->assertSame(1, F24Draft::count());
        // La ripetizione con la stessa request_id restituisce la stessa delega
        $f1 = app(F24Service::class)->generate($this->company, $this->owner, $feb, [], 'f-1');
        $f2 = app(F24Service::class)->generate($this->company, $this->owner, $feb, [], 'f-1');
        $this->assertSame($f1->id, $f2->id);
    }

    public function test_td17_reverse_charge_split_payment_and_pro_rata(): void
    {
        $this->regime(2026, proRata: '100.00');
        $this->doc('acquisto', '2026-06-10', '220.00', 6, 'TD17', '50');
        $d = $this->calc(2026, 6);
        $this->assertSame('220.00', $this->eur($d['figures']['debit']));
        $this->assertSame('110.00', $this->eur($d['figures']['deductible']));
        $this->doc('vendita', '2026-06-12', '500.00', 6, esig: 'S');
        $d = $this->calc(2026, 6);
        $this->assertSame('220.00', $this->eur($d['figures']['debit']));
        $this->assertSame('500.00', $this->eur($d['figures']['split']));
        // IVA per cassa: Da verificare, blocca la conferma
        $this->doc('vendita', '2026-07-12', '100.00', 7, esig: 'D');
        $this->assertNotEmpty($this->calc(2026, 7)['checks']);
        // Pro rata una volta sull'IVA detraibile secondo quota specifica
        $pr = $this->newCompany('11223344556');
        $this->regime(2026, c: $pr, proRata: '80.00');
        $this->doc('acquisto', '2026-06-10', '1000.00', 6, pct: '50', c: $pr);
        $d = $this->calc(2026, 6, $pr);
        $this->assertSame('400.00', $this->eur($d['figures']['deductible']));
        $this->assertSame('100.00', $this->eur($d['figures']['pro_rata_reduction']));
    }

    public function test_setup_issue_names_missing_field(): void
    {
        $issues = $this->calc(2026, 1)['issues'];
        $this->assertSame('regime', $issues[0]['field']);
        $this->regime(2026, 'trimestrale', 'opzione');
        VatProfile::where('company_id', $this->company->id)->update(['quarterly_type' => null]); // situazione preesistente senza indicazione
        $this->assertSame('quarterly_type', $this->calc(2026, 1)['issues'][0]['field'] ?? null);
    }

    public function test_http_pages_pdfs_permissions_and_double_click(): void
    {
        $this->travelTo('2026-02-05 10:00:00');
        $this->regime(2026);
        $c = app(CreditService::class)->registerAnnual($this->company, $this->owner, ['year' => 2025, 'amount' => '500.00']);
        $this->doc('vendita', '2026-01-10', '3000.00', 1);
        $base = "/aziende/{$this->company->id}";
        $this->actingAs($this->owner);
        $this->get("{$base}/liquidazioni?anno=2026")->assertOk()->assertInertia(fn ($p) => $p->component('Liquidazioni/Index')->has('rows', 12)->where('rows.0.result', 'debito'));
        $this->get("{$base}/liquidazioni/2026/1")->assertOk()->assertInertia(fn ($p) => $p->component('Liquidazioni/Show')->where('draft.figures.due', '3000.00')->where('confirmed', null));
        $this->get("{$base}/liquidazioni/2026/13")->assertNotFound();
        $this->assertSame('application/pdf', $this->get("{$base}/liquidazioni/2026/1/pdf")->assertOk()->headers->get('content-type'));
        $this->post("{$base}/liquidazioni/2026/1/conferma", ['request_id' => 'x1'])->assertRedirect();
        $this->post("{$base}/liquidazioni/2026/1/conferma", ['request_id' => 'x1'])->assertSessionHas('success', fn ($m) => str_contains($m, 'già confermata'));
        $this->post("{$base}/liquidazioni/2026/1/conferma", ['request_id' => 'x2'])->assertSessionHas('success', fn ($m) => str_contains($m, 'già confermata'));
        $this->assertSame(1, VatSettlement::count());
        $this->get("{$base}/liquidazioni/2026/1")->assertInertia(fn ($p) => $p->where('confirmed.version', 1)->has('compensable', 1));
        $this->post("{$base}/liquidazioni/2026/1/f24", ['compensations' => [['credit_id' => $c->id, 'amount' => '500,00']], 'request_id' => 'f1'])->assertSessionHas('success');
        $f = F24Draft::firstOrFail();
        $this->assertSame('2500.00', (string) $f->balance);
        $this->assertSame('application/pdf', $this->get("{$base}/f24/{$f->id}/pdf")->assertOk()->headers->get('content-type'));
        $this->get("{$base}/scadenziario/scadenze-fiscali")->assertInertia(fn ($p) => $p->component('Scadenziario/Deadlines')->where('items.0.state', 'aperta'));
        $this->post("{$base}/f24/{$f->id}/presentato", ['date' => '2026-02-05', 'protocol' => 'P1'])->assertSessionHas('success');
        $this->post("{$base}/f24/{$f->id}/addebito", ['date' => '2026-02-05'])->assertSessionHas('success');
        $this->get("{$base}/scadenziario/scadenze-fiscali")->assertInertia(fn ($p) => $p->where('items.0.state', 'chiusa'));
        $this->get("{$base}/crediti-iva?anno=tutti")->assertOk()->assertInertia(fn ($p) => $p->component('Liquidazioni/Credits')->has('credits', 1)->where('credits.0.residual', '0.00'));
        $this->post("{$base}/liquidazioni/2026/1/rettifiche", ['kind' => 'debito', 'amount' => '-10,00', 'causale' => 'Correzione', 'reference' => 'doc 1'])->assertSessionHas('success');
        $this->assertTrue(VatSettlement::first()->review_required);
        $this->post("{$base}/liquidazioni/2026/1/rettifiche", ['kind' => 'acconto', 'amount' => '10', 'causale' => 'x'])->assertSessionHasErrors('kind');

        // Cliente altrui: 404 su liquidazioni, delega, crediti
        $stranger = $this->customer('Altra');
        $this->actingAs($stranger)->get("{$base}/liquidazioni")->assertNotFound();
        $own = $this->companyFor($stranger);
        $this->get("/aziende/{$own->id}/f24/{$f->id}/pdf")->assertNotFound();
        $this->post("/aziende/{$own->id}/crediti-iva/destinazione", ['rows' => [['credit_id' => $c->id, 'riporto' => '1']]])->assertSessionHasErrors('rows.0');
    }

    public function test_credit_destination_page_and_tr_request(): void
    {
        $this->travelTo('2026-04-10 10:00:00');
        $this->regime(2026, 'trimestrale', 'opzione');
        $this->doc('acquisto', '2026-02-10', '3000.00', 1);
        $q1 = $this->confirm(2026, 1);
        $base = "/aziende/{$this->company->id}";
        $this->actingAs($this->owner)->get("{$base}/liquidazioni/2026/1/credito")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Liquidazioni/Credit')->where('tr.eligible', true)->where('tr.admissible', '3000.00')->where('credits.own.0.residual', '3000.00'));
        $this->post("{$base}/liquidazioni/tr/2026/1", ['refund' => '2000', 'compensation' => '1000', 'presupposto' => 'c'])->assertRedirect();
        $claim = VatClaim::firstOrFail();
        $this->assertSame('bozza', $claim->status);
        $this->assertSame('2026-04-30', $claim->deadline->toDateString());
        $own = VatCredit::where('settlement_id', $q1->id)->firstOrFail();
        $this->assertSame(0, $own->residualCents());
        $tr = VatCredit::where('kind', 'trimestrale')->firstOrFail();
        $this->assertSame(100000, $tr->residualCents());
        $this->assertFalse(app(CreditService::class)->availability($tr, 'compensazione', 100000)['ok']); // TR non ancora presentato
        $this->post("{$base}/rimborsi/{$claim->id}/presentata", ['date' => '2026-04-10', 'protocol' => 'TR-1'])->assertSessionHas('success');
        $this->assertTrue(app(CreditService::class)->availability($tr->fresh(), 'compensazione', 100000, '2026-04-16')['ok']);
        $this->post("{$base}/rimborsi/{$claim->id}/respinto", ['date' => '2026-06-10', 'notes' => ''])->assertSessionHasErrors('notes');
        $this->post("{$base}/rimborsi/{$claim->id}/erogato", ['date' => '2026-06-10'])->assertSessionHas('success');
        $this->assertSame('utilizzata', VatCreditMovement::where('claim_id', $claim->id)->value('status'));
    }
}
