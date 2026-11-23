<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\ImmutableRecordException;
use App\Domain\Import\InvoiceImporter;
use App\Domain\RegistrationConflict;
use App\Domain\RegistrationService;
use App\Domain\VatProfileService;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRegistration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** I03: Verifica e registrazione. */
class RegistrationTest extends TestCase
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
        $this->confirmRegime(2026, 'mensile');
    }

    private function confirmRegime(int $year, string $periodicity, ?Company $c = null): void
    {
        $c ??= $this->company;
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($c, $this->owner, $year);
        $svc->updateDraft($c, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => $periodicity]);
        $svc->confirm($c, $d->fresh(), $this->owner);
    }

    private function import(string $xml, ?Company $c = null): Invoice
    {
        $c ??= $this->company;
        $batch = ImportBatch::create(['company_id' => $c->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $c->id, 'filename' => 'f.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $xml, $this->owner->id);

        return $c->invoices()->latest('id')->firstOrFail();
    }

    private function purchase(string $number = 'A1', string $date = '2026-03-10', array $summaries = [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']], string $type = 'TD01'): Invoice
    {
        return $this->import(FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore')->customer('IT01234567897')->body($number, $date, $type, summaries: $summaries)->xml());
    }

    private function sale(string $number = 'V1', string $date = '2026-03-10'): Invoice
    {
        return $this->import(FatturaBuilder::make()->supplier('IT01234567897')->customer('IT22222222222', name: 'Cliente')->body($number, $date)->xml());
    }

    private function account(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function data(Invoice $inv, array $over = []): array
    {
        $isPurchase = $inv->direction?->value === 'acquisto' || ($over['direction'] ?? null) === 'acquisto';

        return [...[
            'receipt_date' => $isPurchase ? '2026-03-12' : null,
            'registration_date' => '2026-03-15',
            'year' => 2026, 'period' => 3,
            'lines' => $inv->vatSummaries->map(fn () => ['account_id' => $this->account($isPurchase ? '602023' : '701003'), 'deductible_pct' => '100'])->all(),
            'acknowledged' => [],
        ], ...$over];
    }

    private function register(Invoice $inv, array $over = []): VatRegistration
    {
        return app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, $this->data($inv, $over));
    }

    private function assertRule(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail("violazione di regola attesa per {$field}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_new_company_gets_simple_chart_of_accounts(): void
    {
        $this->assertSame(186, $this->company->accounts()->count());
        $this->assertSame(['602026', '701003'], $this->company->accounts()->where('is_default', true)->orderBy('code')->pluck('code')->all());
        $this->assertSame(1, $this->company->accounts()->where('type', 'costo')->where('is_default', true)->count());
        $this->assertSame(1, $this->company->accounts()->where('type', 'ricavo')->where('is_default', true)->count());
    }

    public function test_purchase_is_registered_with_protocol_period_and_deductible_vat(): void
    {
        $inv = $this->purchase();
        $reg = $this->register($inv);

        $this->assertSame([1, 2026, 3, 'mensile', 'acquisto', 1], [$reg->protocol, $reg->year, $reg->period, $reg->periodicity, $reg->direction->value, $reg->sign]);
        $this->assertSame(['100.00', '22.00', '22.00'], [$reg->taxable_total, $reg->tax_total, $reg->deductible_total]);
        $this->assertSame('2026-03-12', $reg->receipt_date->toDateString());
        $this->assertSame(InvoiceStatus::Registrata, $inv->fresh()->status);
        $this->assertSame($inv->id, $reg->active_invoice_id);
        $this->assertNotNull($reg->document_id);
        $this->assertSame($inv->document_id, $reg->document_id, 'il file importato appartiene alla registrazione');
        $this->assertDatabaseHas('audit_events', ['action' => 'registration.created', 'subject_id' => $reg->id]);
    }

    public function test_receipt_date_is_proposed_as_invoice_date(): void
    {
        $inv = $this->purchase();
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$inv->id}")
            ->assertInertia(fn ($p) => $p->where('registration.suggest.acquisto.period', (int) substr($inv->doc_date->toDateString(), 5, 2)));
    }

    public function test_partial_deduction_is_rounded_to_cents(): void
    {
        // 22,01 × 40 % = 8,804 → 8,80; 22,02 × 40 % = 8,808 → 8,81
        $inv = $this->purchase(summaries: [['rate' => '22.00', 'taxable' => '100.05', 'tax' => '22.01'], ['rate' => '10.00', 'taxable' => '220.20', 'tax' => '22.02']]);
        $reg = $this->register($inv, ['lines' => [['account_id' => $this->account('602030'), 'deductible_pct' => '40'], ['account_id' => $this->account('602030'), 'deductible_pct' => '40,00']]]);

        $this->assertSame(['8.80', '8.81'], $reg->lines->pluck('deductible_tax')->all());
        $this->assertSame('17.61', $reg->deductible_total);
        $this->assertSame(1, RegistrationService::percentOf(1, 5000), 'il mezzo centesimo viene arrotondato per eccesso');
    }

    public function test_sales_have_their_own_protocol_sequence_and_no_deduction(): void
    {
        $this->register($this->purchase('A1'));
        $this->register($this->purchase('A2'));
        $sale = $this->register($this->sale('V1'), ['receipt_date' => null]);

        $this->assertSame(1, $sale->protocol);
        $this->assertSame('0.00', $sale->deductible_total);
        $this->assertNull($sale->receipt_date);
        $this->assertSame(2, VatRegistration::where('direction', 'acquisto')->max('protocol'));
    }

    public function test_quarterly_regime_uses_quarters(): void
    {
        $other = app(CompanyService::class)->create($this->owner, ['name' => 'Beta', 'tipologia' => 'societa', 'partita_iva' => '09876543217']);
        $this->confirmRegime(2026, 'trimestrale', $other);
        $inv = $this->import(FatturaBuilder::make()->supplier('IT09876543217')->body('Q1', '2026-05-20')->xml(), $other);
        $svc = app(RegistrationService::class);

        $this->assertSame(['year' => 2026, 'period' => 2, 'periodicity' => 'trimestrale'], $svc->suggestedPeriod($other, '2026-05-20'));
        $account = $other->accounts()->where('code', '701003')->value('id');
        $base = ['registration_date' => '2026-06-01', 'year' => 2026, 'lines' => [['account_id' => $account]], 'acknowledged' => []];
        $this->assertRule(fn () => $svc->register($other, $inv, $this->owner, [...$base, 'period' => 5]), 'period');
        $reg = $svc->register($other, $inv, $this->owner, [...$base, 'period' => 2]);
        $this->assertSame([2, 'trimestrale'], [$reg->period, $reg->periodicity]);
    }

    public function test_validation_rules(): void
    {
        $inv = $this->purchase();
        $this->assertRule(fn () => $this->register($inv, ['receipt_date' => null]), 'receipt_date');
        $this->assertRule(fn () => $this->register($inv, ['receipt_date' => '2026-03-01']), 'receipt_date');
        $this->assertRule(fn () => $this->register($inv, ['registration_date' => '2026-12-01']), 'registration_date');
        $this->assertRule(fn () => $this->register($inv, ['period' => 2]), 'period');
        $this->assertRule(fn () => $this->register($inv, ['year' => 2025, 'period' => 3]), 'period');
        $this->assertRule(fn () => $this->register($inv, ['lines' => []]), 'lines');
        $this->assertRule(fn () => $this->register($inv, ['lines' => [['account_id' => $this->account('701003'), 'deductible_pct' => '100']]]), 'lines.0.account_id');
        $this->assertRule(fn () => $this->register($inv, ['lines' => [['account_id' => $this->account('602023'), 'deductible_pct' => '120']]]), 'lines.0.deductible_pct');
        $this->assertSame(0, VatRegistration::count());
    }

    public function test_foreign_and_inactive_accounts_are_rejected(): void
    {
        $other = $this->companyFor($this->customer('Altro'));
        $foreign = $other->accounts()->where('type', 'costo')->value('id');
        $inv = $this->purchase();
        $this->assertRule(fn () => $this->register($inv, ['lines' => [['account_id' => $foreign, 'deductible_pct' => '100']]]), 'lines.0.account_id');

        Account::whereKey($this->account('602023'))->update(['active' => false]);
        $this->assertRule(fn () => $this->register($inv), 'lines.0.account_id');
    }

    public function test_account_outside_vat_scope_is_rejected_and_hidden(): void
    {
        $inv = $this->purchase();
        $this->assertRule(fn () => $this->register($inv, ['lines' => [['account_id' => $this->account('605009'), 'deductible_pct' => '100']]]), 'lines.0.account_id');
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$inv->id}")
            ->assertInertia(fn ($p) => $p->where('registration.accounts', fn ($list) => collect($list)->pluck('code')->doesntContain('605009') && collect($list)->pluck('code')->contains('602030'))
                ->where('registration.accounts', fn ($list) => collect($list)->firstWhere('code', '602030')['iva_detraibile_pct'] === '40.00'));
    }

    public function test_issues_must_be_acknowledged(): void
    {
        $inv = $this->import(FatturaBuilder::make()->supplier('IT01234567897')->customer('IT22222222222')->body('T1', '2026-03-10', total: '999.00')->xml());
        $this->assertSame(InvoiceStatus::DaVerificare, $inv->status);
        $ack = app(RegistrationService::class)->checks($this->company, $inv)['acknowledge'];
        $this->assertCount(1, $ack);

        $this->assertRule(fn () => $this->register($inv), 'acknowledged');
        $reg = $this->register($inv, ['acknowledged' => $ack]);
        $this->assertSame($ack, $reg->acknowledged);
    }

    public function test_regime_issue_from_import_is_resolved_once_regime_exists(): void
    {
        $inv = $this->purchase('OLD', '2025-12-10');
        $this->assertTrue(collect($inv->issues)->contains(fn ($m) => str_contains($m, 'Nessun regime IVA confermato per il 2025')));
        $this->assertRule(fn () => $this->register($inv, ['year' => 2025, 'period' => 12, 'receipt_date' => '2025-12-12', 'registration_date' => '2025-12-15']), 'period');

        $this->confirmRegime(2025, 'mensile');
        $this->assertSame([], app(RegistrationService::class)->checks($this->company, $inv->fresh())['acknowledge']);
        $reg = $this->register($inv, ['year' => 2025, 'period' => 12, 'receipt_date' => '2025-12-12', 'registration_date' => '2025-12-15']);
        $this->assertSame(2025, $reg->year);
    }

    public function test_foreign_currency_is_blocked(): void
    {
        $inv = $this->import(FatturaBuilder::make()->supplier('IT01234567897')->customer('IT22222222222')->body('U1', '2026-03-10', currency: 'USD')->xml());

        $this->assertNotEmpty(app(RegistrationService::class)->checks($this->company, $inv)['blockers']);
        $this->assertRule(fn () => $this->register($inv), 'registration');
    }

    public function test_credit_note_reduces_with_negative_sign(): void
    {
        $inv = $this->purchase('NC1', type: 'TD04', summaries: [['rate' => '22.00', 'taxable' => '-50.00', 'tax' => '-11.00']]);
        $ack = app(RegistrationService::class)->checks($this->company, $inv)['acknowledge'];
        $this->assertTrue(collect($ack)->contains(fn ($m) => str_contains($m, 'importi negativi')));

        $reg = $this->register($inv, ['acknowledged' => $ack]);
        $this->assertSame([-1, '50.00', '11.00', '11.00'], [$reg->sign, $reg->taxable_total, $reg->tax_total, $reg->deductible_total]);
    }

    public function test_autofattura_requires_direction(): void
    {
        $inv = $this->import(FatturaBuilder::make()->supplier('IT01234567897')->customer('IT01234567897')->body('AF1', '2026-03-10', 'TD20')->xml());
        $this->assertNull($inv->direction);
        $ack = app(RegistrationService::class)->checks($this->company, $inv)['acknowledge'];

        $this->assertRule(fn () => $this->register($inv, ['acknowledged' => $ack]), 'direction');
        $reg = $this->register($inv, ['direction' => 'acquisto', 'acknowledged' => $ack]);
        $this->assertSame('acquisto', $inv->fresh()->direction->value);
        $this->assertSame('acquisto', $reg->direction->value);
    }

    public function test_double_registration_is_impossible(): void
    {
        $inv = $this->purchase();
        $first = $this->register($inv);
        try {
            $this->register($inv);
            $this->fail('Doppia registrazione accettata');
        } catch (RegistrationConflict $e) {
            $this->assertSame($first->id, $e->existing->id, 'la registrazione esistente viene indicata');
        }

        $this->expectException(UniqueConstraintViolationException::class);
        VatRegistration::first()->replicate(['protocol'])->forceFill(['protocol' => 99, 'status' => 'registrata'])->saveQuietly();
    }

    public function test_registration_is_immutable(): void
    {
        $reg = $this->register($this->purchase());
        $this->expectException(ImmutableRecordException::class);
        $reg->update(['taxable_total' => '1.00']);
    }

    public function test_reversal_goes_into_the_period_of_its_own_date(): void
    {
        $inv = $this->purchase();
        $reg = $this->register($inv); // marzo 2026
        $this->register($this->purchase('A2'));
        $svc = app(RegistrationService::class);

        $this->assertRule(fn () => $svc->reverse($this->company, $reg, $this->owner, ''), 'reason');
        $this->assertRule(fn () => $svc->reverse($this->company, $reg, $this->owner, 'x', '2026-03-01'), 'date');
        $this->assertRule(fn () => $svc->reverse($this->company, $reg, $this->owner, 'x', '2026-12-01'), 'date');

        // Oggi (18.11.2026) → periodo novembre, non marzo.
        $storno = $svc->reverse($this->company, $reg, $this->owner, 'Conto errato');
        $this->assertSame(['storno', -1, 3, $reg->id], [$storno->status, $storno->sign, $storno->protocol, $storno->reverses_id]);
        $this->assertSame([2026, 11, 'mensile', '2026-11-18', '22.00'], [$storno->year, $storno->period, $storno->periodicity, $storno->registration_date->toDateString(), $storno->deductible_total]);
        $this->assertSame([3, 2026], [$reg->fresh()->period, $reg->fresh()->year], 'l’originale resta nel proprio periodo');
        $this->assertSame('stornata', $reg->fresh()->status);
        $this->assertNull($reg->fresh()->active_invoice_id);
        $this->assertSame(InvoiceStatus::Importata, $inv->fresh()->status);
        $this->assertRule(fn () => $svc->reverse($this->company, $reg->fresh(), $this->owner, 'ancora'), 'registration');

        // Dopo è possibile registrare di nuovo.
        $again = $this->register($inv, ['lines' => [['account_id' => $this->account('602001'), 'deductible_pct' => '100']]]);
        $this->assertSame(4, $again->protocol);
    }

    public function test_reversal_with_chosen_date_uses_that_period_and_year_sequence(): void
    {
        $reg = $this->register($this->purchase());
        $storno = app(RegistrationService::class)->reverse($this->company, $reg, $this->owner, 'Errore', '2026-06-10');
        $this->assertSame([2026, 6], [$storno->year, $storno->period]);

        // Una data di storno in un anno senza regime non è possibile.
        $this->travelTo('2027-01-15');
        $reg2 = $this->register($this->purchase('A9'));
        $this->assertRule(fn () => app(RegistrationService::class)->reverse($this->company, $reg2, $this->owner, 'Errore', '2027-01-15'), 'date');
        $this->confirmRegime(2027, 'trimestrale');
        $s2 = app(RegistrationService::class)->reverse($this->company, $reg2, $this->owner, 'Errore', '2027-01-15');
        $this->assertSame([2027, 1, 'trimestrale', 1], [$s2->year, $s2->period, $s2->periodicity, $s2->protocol], 'il protocollo dell’anno dello storno inizia da 1');
    }

    public function test_discard_and_restore(): void
    {
        $inv = $this->purchase();
        $svc = app(RegistrationService::class);
        $svc->discard($this->company, $inv, $this->owner, 'Duplicato cartaceo');
        $this->assertSame(InvoiceStatus::Scartata, $inv->fresh()->status);
        $this->assertRule(fn () => $this->register($inv), 'registration');

        $svc->restore($this->company, $inv->fresh(), $this->owner);
        $this->assertSame(InvoiceStatus::Importata, $inv->fresh()->status);
        $this->assertNull($inv->fresh()->discard_reason);
    }

    public function test_verification_mode_goes_to_next_document(): void
    {
        $a = $this->purchase('A1', '2026-03-01');
        $b = $this->purchase('A2', '2026-03-05');
        $url = "/aziende/{$this->company->id}";

        $this->actingAs($this->owner)->get("{$url}/verifica")->assertRedirect("{$url}/fatture/{$a->id}?verifica=1");
        $this->actingAs($this->owner)->post("{$url}/fatture/{$a->id}/registra", [...$this->data($a), 'next' => true])
            ->assertRedirect("{$url}/fatture/{$b->id}?verifica=1");
        $this->actingAs($this->owner)->post("{$url}/fatture/{$b->id}/registra", [...$this->data($b), 'next' => true])
            ->assertRedirect("{$url}/fatture?stato=aperte");
        $this->assertSame(2, VatRegistration::count());
    }

    public function test_default_account_is_suggested_without_history(): void
    {
        $inv = $this->purchase();
        $svc = app(RegistrationService::class);
        $this->assertSame($this->account('602026'), $svc->suggestedAccount($this->company, $inv, InvoiceDirection::Acquisto));
        $this->assertSame($this->account('701003'), $svc->suggestedAccount($this->company, $inv, InvoiceDirection::Vendita));
    }

    public function test_invoice_page_offers_suggestions(): void
    {
        $first = $this->purchase('A1');
        $this->register($first, ['lines' => [['account_id' => $this->account('602023'), 'deductible_pct' => '100']]]);
        $second = $this->purchase('A2', '2026-04-02');

        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$second->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Invoices/Show')
                ->where('registration.suggest.account_acquisto', $this->account('602023'))
                ->where('registration.years.2026', 'mensile')
                ->where('registration.blockers', []));
    }

    public function test_other_customer_cannot_register_reverse_or_discard(): void
    {
        $inv = $this->purchase();
        $reg = $this->register($inv);
        $open = $this->purchase('A2');
        $stranger = $this->customer('Estraneo');
        $own = $this->companyFor($stranger);
        $url = "/aziende/{$this->company->id}";

        $this->actingAs($stranger);
        $this->post("{$url}/fatture/{$open->id}/registra", $this->data($open))->assertNotFound();
        $this->post("{$url}/fatture/{$open->id}/scarta", ['reason' => 'x'])->assertNotFound();
        $this->post("{$url}/registrazioni/{$reg->id}/storna", ['reason' => 'x'])->assertNotFound();
        $this->post("/aziende/{$own->id}/fatture/{$open->id}/registra", $this->data($open))->assertNotFound();
        $this->post("/aziende/{$own->id}/registrazioni/{$reg->id}/storna", ['reason' => 'x'])->assertNotFound();
        $this->assertSame(1, VatRegistration::count());
        $this->assertSame(InvoiceStatus::Importata, $open->fresh()->status);
    }
}
