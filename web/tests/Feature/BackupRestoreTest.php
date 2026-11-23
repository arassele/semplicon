<?php

namespace Tests\Feature;

use App\Domain\Archive\CompanyBackup;
use App\Domain\Archive\CompanyRestore;
use App\Domain\AuditChain;
use App\Domain\CatalogService;
use App\Domain\PartnerService;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Partner;
use App\Support\Validation\PartitaIva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;
use ZipArchive;

/** Ripristino dal backup: nuova azienda o sostituzione, checksum, protezione contro file manipolati. */
class BackupRestoreTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    private const TABLES = ['invoices', 'invoice_lines', 'invoice_vat_summaries', 'installments', 'vat_registrations', 'vat_registration_lines', 'xml_exports', 'documents', 'partners', 'catalog_articles', 'issuing_counters', 'accounts', 'vat_profiles'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->owner->update(['password' => Hash::make('segreto-lungo-12')]);
        $this->issueInvoice(['doc_date' => '2026-11-10']);
        $this->issueInvoice(['doc_date' => '2026-11-12']);
        app(PartnerService::class)->save($this->company, $this->owner, null, ['is_customer' => true, 'name' => 'Cliente Uno', 'vat' => '11111111115', 'country' => 'IT']);
        app(CatalogService::class)->saveArticle($this->company, $this->owner, null, ['code' => 'A1', 'description' => 'Consulenza', 'price' => '100', 'rate' => '22']);
    }

    private function counts(int $companyId): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = in_array($t, ['invoice_lines', 'invoice_vat_summaries'], true) ? DB::table($t)->whereIn('invoice_id', DB::table('invoices')->where('company_id', $companyId)->select('id'))->count()
                : ($t === 'vat_registration_lines' ? DB::table($t)->whereIn('vat_registration_id', DB::table('vat_registrations')->where('company_id', $companyId)->select('id'))->count() : DB::table($t)->where('company_id', $companyId)->count());
        }

        return $out;
    }

    private function backup(): string
    {
        $b = app(CompanyBackup::class)->build([$this->company], $this->owner);
        $path = sys_get_temp_dir().'/restore-'.uniqid().'.zip';
        rename($b['path'], $path);

        return $path;
    }

    /** Copiare lo ZIP, modificare/aggiungere un file e riscrivere SHA256SUMS in modo coerente (o volutamente no). */
    private function tamper(string $src, callable $edit, bool $fixSums = true): string
    {
        $in = new ZipArchive;
        $in->open($src);
        $files = [];
        for ($i = 0; $i < $in->numFiles; $i++) {
            $files[$in->getNameIndex($i)] = $in->getFromIndex($i);
        }
        $files = $edit($files);
        if ($fixSums) {
            unset($files['SHA256SUMS']);
            $files['SHA256SUMS'] = implode('', array_map(fn ($n, $c) => hash('sha256', $c)."  {$n}\n", array_keys($files), $files));
        }
        $out = sys_get_temp_dir().'/tamper-'.uniqid().'.zip';
        $z = new ZipArchive;
        $z->open($out, ZipArchive::CREATE);
        foreach ($files as $n => $c) {
            $z->addFromString($n, $c);
        }
        $z->close();

        return $out;
    }

    private function fails(callable $fn, string $key): ValidationException
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());

            return $e;
        }
        $this->fail('Errori di validazione attesi.');
    }

    public function test_replace_restores_the_state_of_the_backup(): void
    {
        $before = $this->counts($this->company->id);
        $zip = $this->backup();
        // poi modificare: un'altra fattura, un altro cliente
        $this->issueInvoice(['doc_date' => '2026-11-15']);
        app(PartnerService::class)->save($this->company, $this->owner, null, ['is_supplier' => true, 'name' => 'Extra', 'vat' => PartitaIva::complete('2222222222'), 'country' => 'IT']);
        $this->assertSame(3, $this->counts($this->company->id)['invoices']);

        $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $zip, 'replace', 'falsch'), 'password');
        $this->assertSame(3, $this->counts($this->company->id)['invoices']); // nulla è successo
        $res = app(CompanyRestore::class)->restore($this->owner, $zip, 'replace', 'segreto-lungo-12');
        $this->assertFalse($res[0]['created']);
        $this->assertSame($before, $this->counts($this->company->id));
        $this->assertSame(1, Partner::where('company_id', $this->company->id)->count());
        $this->assertSame(2, (int) DB::table('issuing_counters')->where('company_id', $this->company->id)->where('scope', '2026')->value('last_number'));
        $this->assertTrue(AuditChain::verify($this->company->fresh())['ok']);
        $this->assertDatabaseHas('audit_events', ['company_id' => $this->company->id, 'action' => 'backup.restored']);
        foreach (DB::table('documents')->where('company_id', $this->company->id)->pluck('storage_path') as $p) {
            Storage::disk('local')->assertExists($p);
        }
        // I numeri proseguono (i conti hanno nuovi identificativi)
        $this->account = (int) $this->company->accounts()->where('type', 'ricavo')->where('iva_rilevante', true)->where('active', true)->orderByDesc('is_default')->value('id');
        $this->assertSame('3/2026', $this->issueInvoice(['doc_date' => '2026-11-16'])->number);
    }

    public function test_restore_as_new_company_after_the_original_was_deleted(): void
    {
        $before = $this->counts($this->company->id);
        $zip = $this->backup();
        $oldId = $this->company->id;
        // L'originale «scompare» (non esiste l'eliminazione di un'azienda): la sua P.IVA viene liberata
        $this->company->update(['partita_iva' => '99999999999']);
        $this->assertSame(0, Company::where('partita_iva', '01234567897')->count());

        $res = app(CompanyRestore::class)->restore($this->owner, $zip, 'new', null);
        $new = Company::where('partita_iva', '01234567897')->sole();
        $this->assertTrue($res[0]['created']);
        $this->assertNotSame($oldId, $new->id);
        $this->assertSame('Alpha S.r.l. (ripristinata)', $new->name);
        $this->assertSame($before, $this->counts($new->id));
        $this->assertTrue(Membership::where('company_id', $new->id)->where('user_id', $this->owner->id)->exists());
        $this->assertTrue(AuditChain::verify($new)['ok']);
        // l'utente vede l'azienda e le sue fatture
        $this->actingAs($this->owner)->get("/aziende/{$new->id}/vendite?anno=2026")->assertOk()->assertInertia(fn ($p) => $p->where('totals.count', 2));
    }

    public function test_guards_new_with_existing_vat_tampered_files_paths_and_tables(): void
    {
        $zip = $this->backup();
        $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $zip, 'new', null), 'mode'); // la P.IVA esiste
        $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $zip, 'bogus', null), 'mode');

        $dir = 'azienda-'.$this->company->id.'-alpha-s-r-l';
        // Contenuto modificato, checksum non adeguato
        $bad = $this->tamper($zip, fn ($f) => [...$f, "$dir/dati/invoices.ndjson" => $f["$dir/dati/invoices.ndjson"].' '], false);
        $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $bad, 'replace', 'segreto-lungo-12'), 'file');
        // Percorso esterno
        $slip = $this->tamper($zip, fn ($f) => [...$f, '../evil.txt' => 'x']);
        $e = $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $slip, 'replace', 'segreto-lungo-12'), 'file');
        $this->assertStringContainsString('percorsi', $e->errors()['file'][0]);
        // file non elencato
        $extra = $this->tamper($zip, fn ($f) => [...$f, 'extra.txt' => 'x'], false);
        $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $extra, 'replace', 'segreto-lungo-12'), 'file');
        // tabella sconosciuta (con checksum corrispondente) e colonna
        $tbl = $this->tamper($zip, fn ($f) => [...$f, "$dir/dati/foo.ndjson" => '{"a":1}'."\n"]);
        $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $tbl, 'replace', 'segreto-lungo-12'), 'file');
        $col = $this->tamper($zip, fn ($f) => [...$f, "$dir/dati/partners.ndjson" => '{"id":1,"company_id":1,"evil_column":"x","is_customer":true}'."\n"]);
        $before = $this->counts($this->company->id);
        $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $col, 'replace', 'segreto-lungo-12'), 'file');
        $this->assertSame($before, $this->counts($this->company->id)); // transazione annullata (dati invariati)
        // nessun backup
        $junk = sys_get_temp_dir().'/junk.zip';
        $z = new ZipArchive;
        $z->open($junk, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('a.txt', 'a');
        $z->close();
        $this->fails(fn () => app(CompanyRestore::class)->restore($this->owner, $junk, 'replace', 'segreto-lungo-12'), 'file');
    }

    public function test_foreign_company_cannot_be_replaced_and_upload_endpoint_works(): void
    {
        $zip = $this->backup();
        $other = $this->customer();
        $other->update(['password' => Hash::make('segreto-lungo-12')]);
        $this->fails(fn () => app(CompanyRestore::class)->restore($other, $zip, 'replace', 'segreto-lungo-12'), 'mode');
        $file = new UploadedFile($zip, 'backup.zip', 'application/zip', null, true);
        $this->actingAs($this->owner)->post('/account/ripristino', ['file' => $file, 'mode' => 'replace', 'password' => 'segreto-lungo-12'])->assertRedirect('/account')->assertSessionHas('success');
        $this->actingAs($this->owner)->post('/account/ripristino', ['mode' => 'new'])->assertSessionHasErrors('file');
    }
}
