<?php

namespace Tests\Feature;

use App\Domain\Archive\YearArchive;
use App\Domain\CompanyService;
use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\VatProfileService;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use ZipArchive;

/** Archivio annuale con checksum SHA-256 (elenco requisiti 1.3). */
class ArchiveTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_archive_contains_files_with_valid_checksums_and_is_audited(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-11-20 10:00:00');
        $u = $this->customer();
        $c = app(CompanyService::class)->create($u, ['name' => 'Alpha S.r.l.', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $c->update(['indirizzo' => 'Via Roma', 'numero_civico' => '10', 'cap' => '20121', 'comune' => 'Milano', 'provincia' => 'MI']);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($c, $u, 2026);
        $svc->updateDraft($c, $d, $u, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($c, $d->fresh(), $u);
        $acc = (int) $c->accounts()->where('type', 'ricavo')->where('iva_rilevante', true)->where('active', true)->orderByDesc('is_default')->value('id');
        app(InvoiceIssuer::class)->issue($c, $u, ['doc_type' => 'TD01', 'doc_date' => '2026-11-18', 'regime' => 'RF01', 'account_id' => $acc, 'payment_mode' => 'MP05',
            'customer' => ['kind' => 'azienda', 'name' => 'Cliente S.r.l.', 'vat' => '11111111115', 'country' => 'IT', 'address' => 'Via Verdi 1', 'zip' => '40121', 'city' => 'Bologna', 'province' => 'BO', 'sdi_code' => 'ABC1234'],
            'lines' => [['description' => 'Consulenza', 'quantity' => '1', 'unit' => 'pz', 'price' => '100,00', 'rate' => '22', 'natura' => '']], 'payments' => [['date' => '2026-12-18', 'amount' => '122,00']]]);

        $a = app(YearArchive::class)->build($c, 2026);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($a['path']));
        $sums = $zip->getFromName('SHA256SUMS');
        $this->assertSame($a['sums_sha256'], hash('sha256', $sums));
        $n = 0;
        foreach (explode("\n", trim($sums)) as $line) {
            [$h, $name] = explode('  ', $line, 2);
            $this->assertSame($h, hash('sha256', (string) $zip->getFromName($name)), $name);
            $n++;
        }
        $this->assertGreaterThanOrEqual(7, $n);
        foreach (['registri/registro-vendite-2026.csv', 'liquidazioni/liquidazioni-2026.csv', 'manifest.json', 'LEGGIMI.txt', 'registro-attivita/verifica-catena.txt'] as $f) {
            $this->assertNotFalse($zip->getFromName($f), $f);
        }
        $this->assertNotEmpty(array_filter(array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn ($x) => str_starts_with($x, 'fatture-emesse/IT01234567897_')));
        $this->assertStringContainsString('Esito: OK', $zip->getFromName('registro-attivita/verifica-catena.txt'));
        $this->assertStringContainsString('Consulenza', $zip->getFromName('registri/registro-vendite-2026.csv'));
        $zip->close();
        @unlink($a['path']);

        $res = $this->actingAs($u)->get("/aziende/{$c->id}/archivio/2026");
        $res->assertOk()->assertHeader('X-Archive-Sums-Sha256');
        $this->assertSame(1, AuditEvent::where('action', 'archive.exported')->count());
        $this->actingAs($u)->get("/aziende/{$c->id}/archivio")->assertOk();
    }
}
