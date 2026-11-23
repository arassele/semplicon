<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\RegistrationService;
use App\Domain\VatProfileService;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Modifica ed eliminazione di registrazioni e fatture (indicazione del committente, 19.11.2026). */
class EditDeleteTest extends TestCase
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

    private string $xml = '';

    private function purchase(string $n = 'A1', array $sums = [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']]): Invoice
    {
        $this->xml = FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore')->customer('IT01234567897')->body($n, '2026-03-10', summaries: $sums)->xml();
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => 'f.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $this->xml, $this->owner->id);

        return $this->company->invoices()->latest('id')->firstOrFail();
    }

    private function acc(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function data(Invoice $inv, array $over = []): array
    {
        return [...['receipt_date' => '2026-03-10', 'registration_date' => '2026-03-12', 'year' => 2026, 'period' => 3, 'acknowledged' => [],
            'lines' => $inv->vatSummaries->map(fn () => ['account_id' => $this->acc('602001'), 'deductible_pct' => '100'])->all()], ...$over];
    }

    private function url(string $s): string
    {
        return "/aziende/{$this->company->id}{$s}";
    }

    public function test_registration_can_be_changed_keeping_its_protocol(): void
    {
        $inv = $this->purchase();
        app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, $this->data($inv));
        $other = $this->purchase('A2');
        app(RegistrationService::class)->register($this->company, $other->fresh(), $this->owner, $this->data($other));
        $reg = VatRegistration::where('invoice_id', $inv->id)->firstOrFail();

        $this->actingAs($this->owner)->put($this->url("/registrazioni/{$reg->id}"), $this->data($inv, ['period' => 4, 'registration_date' => '2026-04-02',
            'lines' => [['account_id' => $this->acc('602030'), 'deductible_pct' => '40']]]))->assertSessionHasNoErrors();
        $new = VatRegistration::where('invoice_id', $inv->id)->firstOrFail();
        $this->assertSame([1, 4, '8.80', '2026-04-02'], [$new->protocol, $new->period, $new->deductible_total, $new->registration_date->toDateString()]);
        $this->assertSame('registrata', $inv->fresh()->status->value);
        $this->assertSame(1, VatRegistration::where('invoice_id', $inv->id)->count());
        $event = AuditEvent::where('action', 'registration.updated')->firstOrFail();
        $this->assertSame('Marzo 2026', $event->data['before']['period']);
        $this->assertSame('Aprile 2026', $event->data['after']['period']);

        // Modifica non valida: nulla viene cambiato.
        $this->actingAs($this->owner)->put($this->url("/registrazioni/{$new->id}"), $this->data($inv, ['lines' => [['account_id' => $this->acc('701003'), 'deductible_pct' => '100']]]))
            ->assertSessionHasErrors('lines.0.account_id');
        $this->assertSame($new->id, VatRegistration::where('invoice_id', $inv->id)->value('id'));
    }

    public function test_registration_can_be_deleted_and_invoice_registered_again(): void
    {
        $inv = $this->purchase();
        $reg = app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, $this->data($inv));
        $this->actingAs($this->owner)->delete($this->url("/registrazioni/{$reg->id}"))->assertSessionHasNoErrors();
        $this->assertSame(0, VatRegistration::count());
        $this->assertSame('importata', $inv->fresh()->status->value);
        $this->assertDatabaseHas('audit_events', ['action' => 'registration.deleted']);
        $again = app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, $this->data($inv));
        $this->assertSame(1, $again->protocol);
    }

    public function test_reversed_registrations_cannot_be_changed_or_deleted_alone(): void
    {
        $inv = $this->purchase();
        $reg = app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, $this->data($inv));
        $storno = app(RegistrationService::class)->reverse($this->company, $reg, $this->owner, 'Errore', '2026-04-01');
        foreach ([$reg->id, $storno->id] as $id) {
            $this->actingAs($this->owner)->delete($this->url("/registrazioni/{$id}"))->assertSessionHasErrors('registration');
            $this->actingAs($this->owner)->put($this->url("/registrazioni/{$id}"), $this->data($inv))->assertSessionHasErrors('registration');
        }
        $this->assertSame(2, VatRegistration::count());
    }

    public function test_registered_purchase_is_not_deleted_but_unregistered_is_archived_and_stays_archived_on_reimport(): void
    {
        // Incarico di sviluppo 19.11.2026, sezione 1 (modifica la decisione n. 65 per gli acquisti).
        $inv = $this->purchase();
        $reg = app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, $this->data($inv));
        $this->actingAs($this->owner)->delete($this->url("/fatture/{$inv->id}"))->assertSessionHasErrors('invoice');
        $this->assertSame('registrata', $inv->fresh()->status->value);

        app(RegistrationService::class)->reverse($this->company, $reg, $this->owner, 'Errore', '2026-04-01');
        $docId = $inv->document_id;
        $this->actingAs($this->owner)->delete($this->url("/fatture/{$inv->id}"))->assertRedirect();
        $this->assertSame(['archiviata', $docId], [$inv->fresh()->status->value, $inv->fresh()->document_id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'invoice.archived']);

        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => 'f.xml', 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $this->xml, $this->owner->id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame('archiviata', $item->fresh()->results[0]['status']);
        $this->assertSame('archiviata', $inv->fresh()->status->value);

        $this->post($this->url("/fatture/{$inv->id}/ripristina-archivio"))->assertRedirect();
        $this->assertSame([true, $docId], [$inv->fresh()->status->isOpen(), $inv->fresh()->document_id]);
    }

    public function test_manual_invoice_can_be_edited_and_deleted_before_registration(): void
    {
        $base = ['direction' => 'acquisto', 'doc_type' => 'TD01', 'number' => 'M-1', 'doc_date' => '2026-04-10', 'counterparty_name' => 'Studio', 'counterparty_vat' => '22222222222',
            'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '100', 'tax' => '']]];
        $this->actingAs($this->owner)->post($this->url('/fatture/nuova'), [...$base, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->url('/fatture/nuova'), [...$base, 'number' => 'M-2'])->assertSessionHasNoErrors();
        $inv = Invoice::where('number', 'M-1')->firstOrFail();
        $oldDoc = $inv->document_id;

        $this->actingAs($this->owner)->get($this->url("/fatture/{$inv->id}/modifica"))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Invoices/Create')->where('invoice.number', 'M-1')->where('invoice.lines.0.taxable', '100,00')->where('invoice.document.name', 'a.pdf'));
        $this->actingAs($this->owner)->post($this->url("/fatture/{$inv->id}/modifica"), [...$base, 'number' => 'M-2'])->assertSessionHasErrors('number');
        $this->actingAs($this->owner)->post($this->url("/fatture/{$inv->id}/modifica"), [...$base, 'number' => 'M-1b', 'lines' => [['rate' => '10', 'natura' => '', 'taxable' => '50', 'tax' => ''], ['rate' => '0', 'natura' => 'N2.2', 'taxable' => '5', 'tax' => '']],
            'file' => UploadedFile::fake()->image('nuovo.png')])->assertSessionHasNoErrors();
        $inv->refresh();
        $this->assertSame(['M-1b', '55.00', '5.00', 'png', 2], [$inv->number, $inv->taxable_total, $inv->tax_total, $inv->document->kind, $inv->vatSummaries()->count()]);
        $this->assertNull(Document::find($oldDoc), 'vecchio allegato rimosso');
        $this->assertDatabaseHas('audit_events', ['action' => 'invoice.updated']);

        $this->actingAs($this->owner)->post($this->url("/fatture/{$inv->id}/modifica"), [...$base, 'number' => 'M-1b', 'remove_attachment' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($inv->fresh()->document_id);
        $this->actingAs($this->owner)->delete($this->url("/fatture/{$inv->id}"))->assertRedirect();
        $this->assertSame([2, 'archiviata'], [Invoice::count(), $inv->fresh()->status->value]); // acquisti: archiviata invece di eliminata
    }

    public function test_registered_manual_invoice_edit_updates_registration(): void
    {
        $base = ['direction' => 'acquisto', 'doc_type' => 'TD01', 'number' => 'M-1', 'doc_date' => '2026-04-10', 'counterparty_name' => 'Studio', 'counterparty_vat' => '22222222222',
            'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '100', 'tax' => '']]];
        $this->actingAs($this->owner)->post($this->url('/fatture/nuova'), $base)->assertSessionHasNoErrors();
        $inv = Invoice::firstOrFail();
        app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, $this->data($inv, ['receipt_date' => '2026-04-10', 'registration_date' => '2026-04-11', 'period' => 4,
            'lines' => [['account_id' => $this->acc('602030'), 'deductible_pct' => '40']]]));

        $this->actingAs($this->owner)->post($this->url("/fatture/{$inv->id}/modifica"), [...$base, 'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '200', 'tax' => '']]])->assertSessionHasNoErrors();
        $reg = VatRegistration::firstOrFail();
        $this->assertSame([1, '200.00', '44.00', '17.60', 4], [$reg->protocol, $reg->taxable_total, $reg->tax_total, $reg->deductible_total, $reg->period]);
        $this->assertSame('registrata', $inv->fresh()->status->value);

        // Numero diverso di righe: la registrazione non corrisponde, nulla viene salvato.
        $this->actingAs($this->owner)->post($this->url("/fatture/{$inv->id}/modifica"), [...$base, 'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '200', 'tax' => ''], ['rate' => '10', 'natura' => '', 'taxable' => '10', 'tax' => '']]])
            ->assertSessionHasErrors('registration');
        $this->assertSame(['200.00', 1], [$inv->fresh()->taxable_total, $inv->fresh()->vatSummaries()->count()]);
        $this->assertSame($reg->id, VatRegistration::value('id'));
    }

    public function test_imported_invoice_cannot_be_edited_and_strangers_get_404(): void
    {
        $inv = $this->purchase();
        $this->actingAs($this->owner)->get($this->url("/fatture/{$inv->id}/modifica"))->assertNotFound();
        $this->actingAs($this->owner)->post($this->url("/fatture/{$inv->id}/modifica"), ['direction' => 'acquisto', 'doc_type' => 'TD01', 'number' => 'X', 'doc_date' => '2026-03-10', 'counterparty_name' => 'X',
            'lines' => [['rate' => '22', 'natura' => '', 'taxable' => '1', 'tax' => '']]])->assertSessionHasErrors('invoice');
        $reg = app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, $this->data($inv));
        $stranger = $this->customer('Estraneo');
        $this->actingAs($stranger)->delete($this->url("/fatture/{$inv->id}"))->assertNotFound();
        $this->actingAs($stranger)->delete($this->url("/registrazioni/{$reg->id}"))->assertNotFound();
        $this->actingAs($stranger)->put($this->url("/registrazioni/{$reg->id}"), $this->data($inv))->assertNotFound();
        $this->actingAs($stranger)->get($this->url("/fatture/{$inv->id}/modifica"))->assertNotFound();
        $this->assertSame(1, VatRegistration::count());
    }
}
