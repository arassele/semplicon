<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\VatProfileService;
use App\Domain\VatRegisterService;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Fatture manuali (senza importazione) con allegato; documento a ogni registrazione. */
class ManualInvoiceTest extends TestCase
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
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'trimestrale']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
    }

    private function payload(array $over = []): array
    {
        return [...['direction' => 'acquisto', 'doc_type' => 'TD01', 'number' => 'F-12', 'doc_date' => '2026-04-10', 'counterparty_name' => 'Studio Verdi',
            'counterparty_vat' => '22222222222', 'counterparty_cf' => '', 'description' => 'Consulenza aprile',
            'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '1000,00', 'tax' => '']]], ...$over];
    }

    private function submit(array $data)
    {
        return $this->actingAs($this->owner)->post("/aziende/{$this->company->id}/fatture/nuova", $data);
    }

    private function acc(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    public function test_form_lists_document_types_and_nature(): void
    {
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/nuova?direzione=vendita")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Invoices/Create')->where('direction', 'vendita')->where('docTypes', fn ($t) => collect($t)->pluck('code')->contains('TD04'))->where('nature', fn ($n) => collect($n)->pluck('code')->contains('N2.2')));
    }

    public function test_manual_purchase_with_attachment_is_registered_with_its_document(): void
    {
        $pdf = UploadedFile::fake()->create('fattura F-12.pdf', 120, 'application/pdf');
        $this->submit([...$this->payload(['lines' => [['rate' => '22', 'natura' => '', 'taxable' => '1000', 'tax' => ''], ['rate' => '0', 'natura' => 'N2.2', 'taxable' => '2,00', 'tax' => '']]]), 'file' => $pdf])
            ->assertSessionHasNoErrors()->assertRedirect();
        $inv = Invoice::firstOrFail();
        $this->assertSame(['manuale', 'MANUALE', 'importata', 'IT22222222222', 'Alpha', '1002.00', '220.00', '1222.00'],
            [$inv->source, $inv->format, $inv->status->value, $inv->supplier_vat, $inv->customer_name, $inv->taxable_total, $inv->tax_total, $inv->total_amount]);
        $this->assertSame(['pdf', 'fattura F-12.pdf'], [$inv->document->kind, $inv->document->original_name]);
        Storage::disk('local')->assertExists($inv->document->storage_path);
        $this->assertDatabaseHas('audit_events', ['action' => 'invoice.created_manual']);

        $this->actingAs($this->owner)->post("/aziende/{$this->company->id}/fatture/{$inv->id}/registra", [
            'receipt_date' => '2026-04-10', 'registration_date' => '2026-04-12', 'year' => 2026, 'period' => 2,
            'lines' => [['account_id' => $this->acc('602001'), 'deductible_pct' => '100'], ['account_id' => $this->acc('602001'), 'deductible_pct' => '100']], 'acknowledged' => [],
        ])->assertSessionHasNoErrors();
        $reg = VatRegistration::firstOrFail();
        $this->assertSame($inv->document_id, $reg->document_id);
        $entry = app(VatRegisterService::class)->build($this->company, 'acquisti', 2026, 2)['entries'][0];
        $this->assertSame('fattura F-12.pdf', $entry['document']['name']);
        $this->assertStringContainsString(';fattura F-12.pdf', app(VatRegisterService::class)->csv($this->company, 'acquisti', 2026, 2));
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/documenti/{$inv->document_id}/scarica")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_manual_sale_and_credit_note(): void
    {
        $this->submit($this->payload(['direction' => 'vendita', 'number' => 'V-1', 'counterparty_name' => 'Cliente Srl']))->assertSessionHasNoErrors();
        $sale = Invoice::where('number', 'V-1')->firstOrFail();
        $this->assertSame(['Alpha', 'IT01234567897', 'Cliente Srl', 'vendita'], [$sale->supplier_name, $sale->supplier_vat, $sale->customer_name, $sale->direction->value]);
        $this->assertNull($sale->document_id, 'allegato facoltativo');

        $this->submit($this->payload(['doc_type' => 'TD04', 'number' => 'NC-1']))->assertSessionHasNoErrors();
        $this->assertSame('TD04', Invoice::where('number', 'NC-1')->value('doc_type'));
    }

    public function test_validation(): void
    {
        $this->submit($this->payload(['lines' => [['rate' => '22', 'natura' => '', 'taxable' => '100', 'tax' => '21,00']]]))->assertSessionHasErrors('lines.0.tax');
        $this->submit($this->payload(['lines' => [['rate' => '0', 'natura' => '', 'taxable' => '100', 'tax' => '']]]))->assertSessionHasErrors('lines.0.natura');
        $this->submit($this->payload(['lines' => [['rate' => '22', 'natura' => 'N4', 'taxable' => '100', 'tax' => '']]]))->assertSessionHasErrors('lines.0.rate');
        $this->submit($this->payload(['lines' => [['rate' => '22', 'natura' => '', 'taxable' => '-5', 'tax' => '']]]))->assertSessionHasErrors('lines.0.taxable');
        $this->submit($this->payload(['doc_type' => 'TD99']))->assertSessionHasErrors('doc_type');
        $this->submit($this->payload(['doc_date' => '2026-12-01']))->assertSessionHasErrors('doc_date');
        $this->submit([...$this->payload(), 'file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
        $this->assertSame(0, Invoice::count());

        $this->submit($this->payload())->assertSessionHasNoErrors();
        $this->submit($this->payload(['description' => 'altra']))->assertSessionHasErrors('number');
        $this->assertSame(1, Invoice::count());
    }

    public function test_attachment_can_be_added_until_registration(): void
    {
        $this->submit($this->payload())->assertSessionHasNoErrors();
        $inv = Invoice::firstOrFail();
        $url = "/aziende/{$this->company->id}/fatture/{$inv->id}/allegato";
        $this->actingAs($this->owner)->post($url, ['file' => UploadedFile::fake()->image('scontrino.jpg')])->assertSessionHasNoErrors();
        $this->assertSame('jpg', $inv->fresh()->document->kind);
        $this->actingAs($this->owner)->post($url, ['file' => UploadedFile::fake()->image('altro.png')])->assertSessionHasErrors('file');
    }

    public function test_other_customer_gets_404(): void
    {
        $this->submit($this->payload())->assertSessionHasNoErrors();
        $inv = Invoice::firstOrFail();
        $stranger = $this->customer('Estraneo');
        $this->actingAs($stranger)->get("/aziende/{$this->company->id}/fatture/nuova")->assertNotFound();
        $this->actingAs($stranger)->post("/aziende/{$this->company->id}/fatture/nuova", $this->payload())->assertNotFound();
        $this->actingAs($stranger)->post("/aziende/{$this->company->id}/fatture/{$inv->id}/allegato", ['file' => UploadedFile::fake()->image('a.jpg')])->assertNotFound();
    }
}
