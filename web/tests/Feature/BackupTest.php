<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\IssuesInvoices;
use Tests\TestCase;
use ZipArchive;

/** Backup d'archivio nell'area utente. */
class BackupTest extends TestCase
{
    use BuildsTenants, IssuesInvoices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    public function test_backup_contains_all_company_data_without_users_and_with_valid_checksums(): void
    {
        $inv = $this->issueInvoice();
        $this->actingAs($this->owner)->get('/account')->assertOk()->assertInertia(fn ($p) => $p->component('Account/Index')->has('companies', 1));
        $res = $this->actingAs($this->owner)->post('/account/backup', ['companies' => [$this->company->id]])->assertOk()->assertHeader('X-Backup-Sha256');
        $tmp = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($tmp, $res->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $sums = $zip->getFromName('SHA256SUMS');
        $this->assertSame($res->headers->get('X-Backup-Sha256'), hash('sha256', $sums));
        foreach (explode("\n", trim($sums)) as $line) {
            [$h, $n] = explode('  ', $line, 2);
            $this->assertSame($h, hash('sha256', (string) $zip->getFromName($n)), $n);
        }
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $dir = 'azienda-'.$this->company->id.'-alpha-s-r-l';
        foreach (["$dir/azienda.json", "$dir/dati/invoices.ndjson", "$dir/dati/invoice_lines.ndjson", "$dir/dati/vat_registrations.ndjson", "$dir/dati/xml_exports.ndjson", "$dir/dati/audit_events.ndjson", 'manifest.json', 'LEGGIMI.txt'] as $f) {
            $this->assertContains($f, $names, $f);
        }
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_starts_with($n, "$dir/documenti/")));
        $this->assertEmpty(array_filter($names, fn ($n) => str_contains($n, 'users') || str_contains($n, 'sessions')));
        $row = json_decode(explode("\n", $zip->getFromName("$dir/dati/invoices.ndjson"))[0], true);
        $this->assertSame($inv->id, $row['id']);
        $this->assertSame(1, AuditEvent::where('action', 'backup.created')->count());
        $this->actingAs($this->owner)->get('/account')->assertInertia(fn ($p) => $p->has('backups', 1));
    }

    public function test_cannot_back_up_foreign_company(): void
    {
        $other = $this->customer();
        $this->actingAs($other)->post('/account/backup', ['companies' => [$this->company->id]])->assertNotFound();
        $this->actingAs($this->owner)->postJson('/account/backup', ['companies' => []])->assertStatus(422);
    }
}
