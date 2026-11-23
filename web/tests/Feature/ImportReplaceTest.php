<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\InvoiceService;
use App\Domain\RegistrationService;
use App\Domain\VatProfileService;
use App\Models\Company;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;

/** Importazione: sostituire, controllo del codice fiscale disattivabile, eliminare il file importato e reimportarlo. */
class ImportReplaceTest extends TestCase
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

    private function xml(string $n, string $taxable = '100.00', string $tax = '22.00', string $customer = 'IT01234567897'): string
    {
        return FatturaBuilder::make()->supplier('IT11111111115', name: 'Fornitore')->customer($customer)->body($n, '2026-03-10', summaries: [['rate' => '22.00', 'taxable' => $taxable, 'tax' => $tax]])->xml();
    }

    private function import(string $bytes, string $mode = 'salta', string $name = 'f.xml'): ImportItem
    {
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda', 'mode' => $mode]);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => $name, 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $bytes, $this->owner->id, $mode);

        return $item->fresh();
    }

    private function register(Invoice $inv): void
    {
        $acc = $this->company->accounts()->where('code', '602023')->value('id');
        app(RegistrationService::class)->register($this->company, $inv->fresh(), $this->owner, ['receipt_date' => '2026-03-12', 'registration_date' => '2026-03-15', 'year' => 2026, 'period' => 3,
            'lines' => $inv->vatSummaries->map(fn () => ['account_id' => $acc, 'deductible_pct' => '100'])->all(), 'acknowledged' => []]);
    }

    public function test_replace_mode_replaces_same_file_and_changed_content(): void
    {
        $a = $this->xml('A1');
        $this->import($a);
        $old = Invoice::sole();
        $this->import($a, 'salta');
        $this->assertSame($old->id, Invoice::sole()->id); // «salta» non cambia nulla
        $this->import($a, 'sostituisci');
        $this->assertNotSame($old->id, Invoice::sole()->id); // stesso file: sostituito
        $this->assertSame(1, Document::count());

        $new = $this->xml('A1', '200.00', '44.00'); // stesso numero, contenuto diverso
        $this->import($new, 'salta');
        $this->assertSame(2, Invoice::count()); // conflitto (due versioni)
        $this->import($new, 'sostituisci', 'g.xml');
        $this->assertSame(1, Invoice::count()); // entrambe le versioni sostituite dalla nuova
        $this->assertSame('200.00', (string) Invoice::orderByDesc('id')->first()->taxable_total);
    }

    public function test_registered_invoice_is_not_replaced_or_deleted(): void
    {
        $a = $this->xml('A1');
        $item = $this->import($a);
        $inv = Invoice::sole();
        $this->register($inv);
        $res = $this->import($a, 'sostituisci');
        $this->assertSame($inv->id, Invoice::sole()->id);
        $this->assertStringContainsString('Non sostituita', implode(' ', $res->messages ?? []));
        $this->expectException(ValidationException::class);
        try {
            app(InvoiceService::class)->deleteDocument($this->company, Document::sole(), $this->owner);
        } finally {
            $this->assertSame(1, Invoice::count());
            $this->assertSame(1, Document::count());
        }
    }

    public function test_delete_imported_file_and_import_again(): void
    {
        $a = $this->xml('A1');
        $this->import($a);
        $doc = Document::sole();
        $this->actingAs($this->owner)->delete("/aziende/{$this->company->id}/documenti/{$doc->id}")->assertSessionDoesntHaveErrors();
        $this->assertSame([0, 0], [Invoice::count(), Document::count()]);
        Storage::disk('local')->assertMissing($doc->storage_path);
        $this->assertDatabaseHas('audit_events', ['action' => 'import.file_deleted']);
        $item = $this->import($a); // di nuovo importabile
        $this->assertSame(1, Invoice::count());
        $this->assertNotNull($item->document_id);
        // azienda altrui
        $this->actingAs($this->customer())->delete("/aziende/{$this->company->id}/documenti/".Document::sole()->id)->assertNotFound();
    }

    public function test_vat_check_can_be_switched_off(): void
    {
        $foreign = $this->xml('X1', customer: 'IT22222222222'); // cedente e cessionario non sono questa azienda
        $item = $this->import($foreign);
        $this->assertSame(0, Invoice::count());
        $this->assertSame('azienda_non_corrispondente', $item->results[0]['status']);
        $this->company->update(['options' => ['import_check_vat' => false]]);
        $this->import($this->xml('X1', customer: 'IT22222222222'), 'sostituisci', 'again.xml');
        $inv = Invoice::sole();
        $this->assertNull($inv->direction); // la direzione deve essere stabilita dall'utente
    }
}
