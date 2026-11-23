<?php

namespace Tests\Feature;

use App\Domain\CompanyService;
use App\Domain\Import\InvoiceImporter;
use App\Domain\VatProfileService;
use App\Enums\ImportItemStatus;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Jobs\ProcessImportBatch;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FatturaBuilder;
use Tests\TestCase;
use ZipArchive;

/** I02: importazione di FatturaPA. I valori attesi provengono dai file di esempio ufficiali. */
class ImportTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;

    private Company $company;   // P.IVA come cedente degli esempi ufficiali

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = $this->customer();
        $this->company = app(CompanyService::class)->create($this->owner, ['name' => 'Alpha', 'tipologia' => 'societa', 'partita_iva' => '01234567890']);
    }

    private function official(string $name): string
    {
        return file_get_contents(base_path("tests/fixtures/fatturapa/official/{$name}"));
    }

    private function import(string $bytes, string $filename = 'fattura.xml', ?Company $company = null): ImportItem
    {
        $company ??= $this->company;
        $batch = ImportBatch::create(['company_id' => $company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $company->id, 'filename' => $filename, 'status' => 'in_attesa']);
        app(InvoiceImporter::class)->import($item, $bytes, $this->owner->id);

        return $item->fresh();
    }

    private function upload(array $files, ?User $as = null, ?Company $company = null)
    {
        $company ??= $this->company;

        return $this->actingAs($as ?? $this->owner)->post("/aziende/{$company->id}/importa", ['files' => $files]);
    }

    // File di riferimento

    public function test_official_fpr01_is_imported_as_sale_with_values_from_the_file(): void
    {
        $bytes = $this->official('IT01234567890_FPR01.xml');
        $item = $this->import($bytes, 'IT01234567890_FPR01.xml');

        $this->assertContains($item->status, [ImportItemStatus::Importato, ImportItemStatus::DaVerificare]);
        $inv = $this->company->invoices()->sole();
        $this->assertSame(InvoiceDirection::Vendita, $inv->direction);
        $this->assertSame(['FPR12', 'TD01', '123', '2014-12-18', 'EUR'], [$inv->format, $inv->doc_type, $inv->number, $inv->doc_date->toDateString(), $inv->currency]);
        $this->assertSame(['5.00', '1.10'], [$inv->taxable_total, $inv->tax_total]);
        $this->assertSame("SOCIETA' ALPHA SRL", $inv->supplier_name);
        $this->assertSame('DITTA BETA', $inv->customer_name);
        $this->assertSame('09876543210', $inv->customer_cf);
        $this->assertSame(['22.00', '5.00', '1.10', 'I'], [$inv->vatSummaries[0]->rate, $inv->vatSummaries[0]->taxable, $inv->vatSummaries[0]->tax, $inv->vatSummaries[0]->esigibilita]);
        $this->assertSame(['5.00000000', '1.00000000', '5.00000000'], [$inv->lines[0]->quantity, $inv->lines[0]->unit_price, $inv->lines[0]->total]);

        // Originale archiviato invariato.
        $doc = $inv->document;
        $this->assertSame(hash('sha256', $bytes), $doc->sha256);
        $this->assertSame($bytes, Storage::disk('local')->get($doc->storage_path));
        $this->assertStringStartsWith("documents/{$this->company->id}/", $doc->storage_path);
    }

    public function test_same_file_is_a_purchase_for_the_customer(): void
    {
        $buyer = app(CompanyService::class)->create($this->owner, ['name' => 'Beta', 'tipologia' => 'ditta_individuale', 'partita_iva' => '09876543217', 'codice_fiscale' => '09876543210']);
        $this->import($this->official('IT01234567890_FPR01.xml'), 'a.xml', $buyer);

        $this->assertSame(InvoiceDirection::Acquisto, $buyer->invoices()->sole()->direction);
    }

    public function test_multiple_bodies_create_multiple_invoices(): void
    {
        $this->import($this->official('IT01234567890_FPR03.xml'));

        $invoices = $this->company->invoices()->orderBy('body_index')->get();
        $this->assertSame([1, 2], $invoices->pluck('body_index')->all());
        $this->assertSame(['123', '456'], $invoices->pluck('number')->all());
        $this->assertSame('2000.00', $invoices[1]->taxable_total);
        $this->assertSame('440.00', $invoices[1]->tax_total);
    }

    public function test_official_fpa_examples_are_supported(): void
    {
        $this->import($this->official('IT01234567890_FPA03.xml'));
        $this->assertSame(['FPA12', 'FPA12'], $this->company->invoices()->pluck('format')->all());
    }

    public function test_schema_violation_is_rejected_with_explanation(): void
    {
        $item = $this->import($this->official('IT01234567890_FPR02.xml'));

        $this->assertSame(ImportItemStatus::Errore, $item->status);
        $this->assertStringContainsString('schema ufficiale FatturaPA 1.2.3', $item->messages[0]);
        $this->assertStringContainsString('ContattiTrasmittente', $item->messages[1]);
        $this->assertSame(0, $this->company->invoices()->count());
        $this->assertNotNull($item->document_id, 'l’originale viene comunque conservato');
    }

    // Duplicati

    public function test_same_file_twice_is_a_duplicate(): void
    {
        $bytes = $this->official('IT01234567890_FPR01.xml');
        $this->import($bytes);
        $second = $this->import($bytes);

        $this->assertSame(ImportItemStatus::Duplicato, $second->status);
        $this->assertSame(1, $this->company->invoices()->count());
        $this->assertSame(1, $this->company->documents()->count());
    }

    public function test_same_invoice_in_another_file_is_a_duplicate(): void
    {
        $xml = FatturaBuilder::make()->supplier('IT01234567890')->body('77');
        $this->import($xml->progressivo('00001')->xml());
        $second = $this->import($xml->progressivo('00002')->xml(), 'altro.xml');

        $this->assertSame(ImportItemStatus::Duplicato, $second->status);
        $this->assertSame(2, $this->company->documents()->count(), 'due file diversi');
        $this->assertSame(1, $this->company->invoices()->count(), 'ma una sola fattura');
    }

    public function test_same_identity_with_different_content_is_held_for_review(): void
    {
        $this->import(FatturaBuilder::make()->supplier('IT01234567890')->body('9')->xml());
        $item = $this->import(FatturaBuilder::make()->supplier('IT01234567890')->body('9', summaries: [['rate' => '22.00', 'taxable' => '200.00', 'tax' => '44.00']])->xml(), 'b.xml');

        $this->assertSame(ImportItemStatus::DaVerificare, $item->status);
        $second = $this->company->invoices()->latest('id')->first();
        $this->assertSame(InvoiceStatus::DaVerificare, $second->status);
        $this->assertNotNull($second->conflicts_with);
        $this->assertTrue(collect($second->issues)->contains(fn ($m) => str_contains($m, 'contenuto diverso')));
    }

    public function test_database_rejects_duplicate_identity_and_content(): void
    {
        $this->import(FatturaBuilder::make()->supplier('IT01234567890')->xml());
        $copy = $this->company->invoices()->sole()->replicate();

        $this->expectException(UniqueConstraintViolationException::class);
        $copy->save();
    }

    public function test_same_number_from_different_suppliers_is_not_a_duplicate(): void
    {
        $this->import(FatturaBuilder::make()->supplier('IT11111111111')->customer('IT01234567890')->body('1')->xml());
        $item = $this->import(FatturaBuilder::make()->supplier('IT33333333333', name: 'Altro fornitore')->customer('IT01234567890')->body('1')->xml(), 'b.xml');

        $this->assertNotSame(ImportItemStatus::Duplicato, $item->status);
        $this->assertSame(2, $this->company->invoices()->where('direction', 'acquisto')->count());
    }

    // Avvisi di verifica

    public function test_foreign_currency_and_total_mismatch_are_flagged(): void
    {
        $this->import(FatturaBuilder::make()->supplier('IT01234567890')->body('5', currency: 'USD', total: '130.00')->xml());

        $inv = $this->company->invoices()->sole();
        $this->assertSame(InvoiceStatus::DaVerificare, $inv->status);
        $this->assertTrue(collect($inv->issues)->contains(fn ($m) => str_contains($m, 'Valuta USD')));
        $this->assertTrue(collect($inv->issues)->contains(fn ($m) => str_contains($m, 'non coincide')));
    }

    public function test_invoice_without_issues_when_regime_is_confirmed(): void
    {
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2026);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);

        $item = $this->import(FatturaBuilder::make()->supplier('IT01234567890')->body('8', total: '122.00')->xml());

        $this->assertSame(ImportItemStatus::Importato, $item->status);
        $this->assertSame(InvoiceStatus::Importata, $this->company->invoices()->sole()->status);
    }

    public function test_document_types_and_natura_come_from_the_schema(): void
    {
        $this->import(FatturaBuilder::make()->supplier('IT01234567890')->body('NC1', type: 'TD04', summaries: [['rate' => '0.00', 'taxable' => '10.00', 'tax' => '0.00', 'natura' => 'N6.3']])->xml());

        $inv = $this->company->invoices()->sole();
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$inv->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('invoice.doc_type_label', 'Nota di credito')
                ->where('invoice.summaries.0.natura_label', 'Inversione contabile - subappalto nel settore edile'));
    }

    // Sicurezza ed errori

    public function test_xxe_and_doctype_are_rejected(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">&e;</p:FatturaElettronica>';
        $item = $this->import($xxe, 'xxe.xml');

        $this->assertSame(ImportItemStatus::Errore, $item->status);
        $this->assertStringContainsString('DOCTYPE', $item->messages[0]);
        $this->assertStringNotContainsString('root:', json_encode($item->messages));
    }

    public function test_invalid_xml_and_wrong_format_are_rejected(): void
    {
        $this->assertSame(ImportItemStatus::Errore, $this->import('<non chiuso', 'a.xml')->status);
        $item = $this->import('<?xml version="1.0"?><altro/>', 'b.xml');
        $this->assertStringContainsString('non è una fattura elettronica', $item->messages[0]);
    }

    public function test_file_for_another_company_is_rejected(): void
    {
        $item = $this->import(FatturaBuilder::make()->supplier('IT11111111111')->customer('IT22222222222')->xml());

        $this->assertSame(ImportItemStatus::Errore, $item->status);
        $this->assertStringContainsString('non riguarda questa azienda', $item->messages[0]);
        $this->assertSame(0, $this->company->invoices()->count());
    }

    // P7M

    private function sign(string $content, bool $base64 = false): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Firma di prova'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'in');
        $out = tempnam($dir, 'out');
        file_put_contents($in, $content);
        openssl_cms_sign($in, $out, $cert, $key, [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
        $der = file_get_contents($out);
        @unlink($in);
        @unlink($out);

        return $base64 ? chunk_split(base64_encode($der)) : $der;
    }

    public function test_signed_p7m_der_and_base64_are_imported(): void
    {
        $this->import($this->sign(FatturaBuilder::make()->supplier('IT01234567890')->body('P1')->xml()), 'IT1_001.xml.p7m');
        $this->import($this->sign(FatturaBuilder::make()->supplier('IT01234567890')->body('P2')->xml(), true), 'IT1_002.xml.p7m');

        $this->assertSame(['P1', 'P2'], $this->company->invoices()->orderBy('number')->pluck('number')->all());
        $this->assertSame(['p7m', 'p7m'], $this->company->documents()->pluck('kind')->all());
    }

    public function test_tampered_p7m_is_rejected(): void
    {
        $der = $this->sign(FatturaBuilder::make()->supplier('IT01234567890')->body('P3')->xml());
        $tampered = str_replace('P3', 'P4', $der);
        $item = $this->import($tampered, 'x.xml.p7m');

        $this->assertSame(ImportItemStatus::Errore, $item->status);
        $this->assertStringContainsString('.p7m', $item->messages[0]);
    }

    // Upload, ZIP, job

    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        return new UploadedFile($path, 'fatture.zip', 'application/zip', null, true);
    }

    public function test_upload_runs_the_import_and_cleans_up(): void
    {
        $file = UploadedFile::fake()->createWithContent('IT01234567890_FPR01.xml', $this->official('IT01234567890_FPR01.xml'));
        $response = $this->upload([$file]);

        $batch = ImportBatch::sole();
        $response->assertRedirect("/aziende/{$this->company->id}/importa/{$batch->id}");
        $this->assertSame('completato', $batch->status->value);
        $this->assertSame(1, $batch->invoices);
        $this->assertSame([], Storage::disk('local')->allFiles("imports/{$this->company->id}"), 'file intermedi eliminati');
        $this->assertDatabaseHas('audit_events', ['action' => 'import.completed', 'company_id' => $this->company->id]);

        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/importa/{$batch->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Import/Show')->where('batch.invoices', 1)->has('items', 1));
    }

    public function test_zip_is_expanded_safely(): void
    {
        $zip = $this->zip([
            'cartella/IT01234567890_FPR01.xml' => $this->official('IT01234567890_FPR01.xml'),
            '../../fuori.xml' => FatturaBuilder::make()->supplier('IT01234567890')->body('Z1')->xml(),
            'leggimi.txt' => 'testo',
            'annidato.zip' => 'PK',
            '__MACOSX/._x.xml' => 'metadati',
        ]);
        $this->upload([$zip]);

        $items = ImportItem::orderBy('id')->get();
        $archive = $items->firstWhere('parent_id', null);
        $this->assertSame(ImportItemStatus::Archivio, $archive->status);
        $this->assertTrue(collect($archive->messages)->contains(fn ($m) => str_contains($m, 'leggimi.txt')));
        $this->assertTrue(collect($archive->messages)->contains(fn ($m) => str_contains($m, 'annidati')));
        $children = $items->whereNotNull('parent_id');
        $this->assertCount(2, $children);
        $this->assertTrue($children->contains(fn ($c) => str_ends_with($c->filename, '› fuori.xml')), 'solo il nome del file, nessun percorso');
        $this->assertSame(2, $this->company->invoices()->count());
        foreach ($this->company->documents as $doc) {
            $this->assertStringStartsWith("documents/{$this->company->id}/", $doc->storage_path);
        }
    }

    public function test_zip_with_too_many_entries_is_rejected(): void
    {
        config(['semplicon.import.max_zip_entries' => 2]);
        $this->upload([$this->zip(['a.xml' => 'x', 'b.xml' => 'y', 'c.xml' => 'z'])]);

        $item = ImportItem::sole();
        $this->assertSame(ImportItemStatus::Errore, $item->status);
        $this->assertStringContainsString('troppi file', $item->messages[0]);
    }

    public function test_upload_validation(): void
    {
        $this->upload([UploadedFile::fake()->create('fattura.pdf', 10)])->assertSessionHasErrors('files.0');
        $this->upload([])->assertSessionHasErrors('files');
        config(['semplicon.import.max_files_per_upload' => 2]);
        $this->upload([UploadedFile::fake()->create('a.xml', 1), UploadedFile::fake()->create('b.xml', 1), UploadedFile::fake()->create('c.xml', 1)])
            ->assertSessionHasErrors('files');
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_job_ignores_items_of_another_company(): void
    {
        $other = $this->companyFor($this->customer('Altro'));
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        Storage::disk('local')->put("imports/{$other->id}/x/a.bin", $this->official('IT01234567890_FPR01.xml'));
        $foreign = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $other->id, 'filename' => 'a.xml', 'staging_path' => "imports/{$other->id}/x/a.bin", 'status' => 'in_attesa']);

        (new ProcessImportBatch($batch->id))->handle(app(InvoiceImporter::class));

        $this->assertSame(ImportItemStatus::Errore, $foreign->fresh()->status);
        $this->assertSame(0, Invoice::count());
    }

    // Separazione dei mandanti e download

    public function test_other_customer_cannot_see_or_download(): void
    {
        $this->upload([UploadedFile::fake()->createWithContent('f.xml', $this->official('IT01234567890_FPR01.xml'))]);
        $batch = ImportBatch::sole();
        $invoice = Invoice::sole();
        $stranger = $this->customer('Estraneo');
        $own = $this->companyFor($stranger);

        $this->actingAs($stranger);
        $this->get("/aziende/{$this->company->id}/importa")->assertNotFound();
        $this->get("/aziende/{$this->company->id}/importa/{$batch->id}")->assertNotFound();
        $this->get("/aziende/{$this->company->id}/fatture/{$invoice->id}")->assertNotFound();
        $this->get("/aziende/{$this->company->id}/documenti/{$invoice->document_id}/scarica")->assertNotFound();
        $this->post("/aziende/{$this->company->id}/importa", ['files' => [UploadedFile::fake()->create('a.xml', 1)]])->assertNotFound();
        // ID altrui sotto la propria azienda:
        $this->get("/aziende/{$own->id}/importa/{$batch->id}")->assertNotFound();
        $this->get("/aziende/{$own->id}/fatture/{$invoice->id}")->assertNotFound();
        $this->get("/aziende/{$own->id}/documenti/{$invoice->document_id}/scarica")->assertNotFound();
    }

    public function test_download_returns_the_original_and_is_logged(): void
    {
        $bytes = $this->official('IT01234567890_FPR01.xml');
        $this->upload([UploadedFile::fake()->createWithContent('IT01234567890_FPR01.xml', $bytes)]);
        $doc = $this->company->documents()->sole();

        $response = $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/documenti/{$doc->id}/scarica");
        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertTrue(AuditEvent::where('action', 'document.downloaded')->exists());

        $admin = $this->admin();
        $this->actingAs($admin)->get("/aziende/{$this->company->id}/documenti/{$doc->id}/scarica")->assertOk();
    }

    public function test_invoice_list_filters(): void
    {
        $this->import(FatturaBuilder::make()->supplier('IT01234567890')->customer('IT22222222222', name: 'Rossi Mario')->body('V1')->xml());
        $this->import(FatturaBuilder::make()->supplier('IT33333333333', name: 'Fornitore Verdi')->customer('IT01234567890')->body('A1', '2025-05-05')->xml(), 'b.xml');
        $url = "/aziende/{$this->company->id}";

        $this->actingAs($this->owner)->get("{$url}/vendite")->assertInertia(fn ($p) => $p->has('invoices.data', 1)->where('invoices.data.0.number', 'V1')->where('invoices.data.0.counterparty', 'Rossi Mario'));
        $this->actingAs($this->owner)->get("{$url}/acquisti?anno=tutti")->assertInertia(fn ($p) => $p->has('invoices.data', 1)->where('invoices.data.0.counterparty', 'Fornitore Verdi'));
        $this->actingAs($this->owner)->get("{$url}/acquisti")->assertInertia(fn ($p) => $p->has('invoices.data', 0)->where('filters.anno', 2026)); // standard: anno contabile attivo
        $this->actingAs($this->owner)->get("{$url}/fatture?anno=tutti&q=verdi")->assertInertia(fn ($p) => $p->has('invoices.data', 1));
        $this->actingAs($this->owner)->get("{$url}/fatture?anno=2025")->assertInertia(fn ($p) => $p->has('invoices.data', 1)->where('invoices.data.0.number', 'A1'));
        $this->actingAs($this->owner)->get("{$url}/fatture?anno=tutti&stato=da_verificare")->assertInertia(fn ($p) => $p->has('invoices.data', 2));
    }

    /* ---- Briefing 18.11.2026, ticket 08: risultato per documento, conflitti ---- */

    private function sample(string $n, string $taxable = '100.00', string $tax = '22.00'): string
    {
        return FatturaBuilder::make()->supplier('IT11111111115', name: 'Hosting Alpha')->customer('IT01234567890')->body($n, '2026-03-10', summaries: [['rate' => '22.00', 'taxable' => $taxable, 'tax' => $tax]])->xml();
    }

    private function batchOf(array $files): ImportBatch
    {
        $batch = ImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'in_coda']);
        foreach ($files as $name => $bytes) {
            $item = ImportItem::create(['import_batch_id' => $batch->id, 'company_id' => $this->company->id, 'filename' => $name, 'status' => 'in_attesa']);
            app(InvoiceImporter::class)->import($item, $bytes, $this->owner->id);
        }

        return $batch;
    }

    public function test_08_reimport_reports_each_document_as_already_present(): void
    {
        $files = ['a.xml' => $this->sample('A1'), 'b.xml' => $this->sample('A2'), 'c.xml' => $this->sample('A3')];
        $this->batchOf($files);
        $again = $this->batchOf($files);
        $this->assertSame(3, Invoice::count());
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/importa/{$again->id}")->assertInertia(fn ($p) => $p
            ->where('summary', '3 documenti già presenti. Nessuna nuova fattura da registrare.')
            ->where('items.0.results.0.status', 'gia_presente')->where('items.0.results.0.number', 'A1')
            ->where('items.0.results.0.existing_id', Invoice::where('number', 'A1')->value('id')));
    }

    public function test_08_reformatted_copy_is_not_a_new_document(): void
    {
        $xml = $this->sample('A1');
        $this->import($xml);
        $pretty = str_replace('><', ">\n    <", $xml); // modificata solo la formattazione
        $item = $this->import($pretty, 'copia.xml');
        $this->assertSame(1, Invoice::count());
        $this->assertSame('gia_presente', $item->results[0]['status']);
    }

    public function test_08_different_amount_is_a_conflict_with_comparison(): void
    {
        $this->import($this->sample('A1'));
        $item = $this->import($this->sample('A1', '120.00', '26.40'), 'diverso.xml');
        $r = $item->results[0];
        $this->assertSame('conflitto', $r['status']);
        $old = Invoice::where('taxable_total', '100.00')->firstOrFail();
        $new = Invoice::findOrFail($r['invoice_id']);
        $this->assertSame([$old->id, $old->id, 'da_verificare'], [$r['existing_id'], $new->conflicts_with, $new->status->value]);
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$new->id}/confronta/{$old->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Invoices/Compare')->where('a.taxable_total', '120.00')->where('b.taxable_total', '100.00'));
        $other = $this->companyFor($this->customer('Altro'));
        $this->actingAs($this->owner)->get("/aziende/{$this->company->id}/fatture/{$new->id}/confronta/".Invoice::create([...$old->only(['document_id', 'body_index', 'format', 'doc_type', 'number', 'doc_date', 'currency', 'taxable_total', 'tax_total', 'supplier_name', 'customer_name', 'identity_key', 'status']), 'company_id' => $other->id, 'content_hash' => str_repeat('a', 64)])->id)->assertNotFound();
    }

    public function test_08_multi_body_file_reports_each_body(): void
    {
        $this->import($this->sample('B1'));
        $multi = FatturaBuilder::make()->supplier('IT11111111115', name: 'Hosting Alpha')->customer('IT01234567890')
            ->body('B1', '2026-03-10', summaries: [['rate' => '22.00', 'taxable' => '999.00', 'tax' => '219.78']])
            ->body('B2', '2026-03-11')->xml();
        $item = $this->import($multi, 'lotto.xml');
        $this->assertSame(['conflitto', 'importata'], array_column($item->results, 'status'));
        $this->assertSame([1, 2], array_column($item->results, 'body'));
    }

    public function test_08_foreign_company_and_unreadable_file_are_explained(): void
    {
        $foreign = FatturaBuilder::make()->supplier('IT11111111115', name: 'Hosting Alpha')->customer('IT22222222222', name: 'Altra Srl')->body('X1')->xml();
        $r = $this->import($foreign, 'altro.xml')->results[0];
        $this->assertSame(['azienda_non_corrispondente', 'Altra Srl', 'IT22222222222'], [$r['status'], $r['parties']['cessionario']['name'], $r['parties']['cessionario']['vat']]);
        $bad = $this->import('<?xml version="1.0"?><nonfattura/>', 'rotto.xml')->results[0];
        $this->assertSame('non_leggibile', $bad['status']);
        $this->assertNotSame('', $bad['message']);
    }
}
