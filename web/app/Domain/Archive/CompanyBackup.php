<?php

namespace App\Domain\Archive;

use App\Domain\Audit;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Backup d'archivio nell'area utente: tutti i dati di una o più aziende su tutti gli anni come ZIP (NDJSON per tabella, file originali,
 * manifest e checksum SHA-256). Non contiene account utente né password. Il ripristino è gestito da CompanyRestore
 * (Account › Ripristina da backup).
 */
final class CompanyBackup
{
    /** Tabelle figlie senza company_id proprio: tabella => [colonna padre, tabella padre]. */
    private const CHILDREN = ['invoice_lines' => ['invoice_id', 'invoices'], 'invoice_vat_summaries' => ['invoice_id', 'invoices'], 'vat_registration_lines' => ['vat_registration_id', 'vat_registrations']];

    /** Tabelle che non rientrano mai in un backup (utenti, sessioni, coda, dati di riferimento). */
    private const SKIP = ['users', 'sessions', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'companies', 'ateco_codes'];

    /** @param  list<Company>  $companies  @return array{path: string, filename: string, sha256: string, files: int, companies: int} */
    public function build(array $companies, User $actor): array
    {
        $path = tempnam(sys_get_temp_dir(), 'semplicon-backup');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Backup non creabile.');
        }
        $tables = array_values(array_filter(array_map(fn ($t) => $t['name'], Schema::getTables()), fn ($n) => ! in_array($n, self::SKIP, true)));
        $sums = '';
        $files = 0;
        $sha = [];
        $add = function (string $name, string $content) use ($zip, &$sums, &$files) {
            $zip->addFromString($name, $content);
            $sums .= hash('sha256', $content)."  {$name}\n";
            $files++;
        };
        $root = fn (Company $c) => 'azienda-'.$c->id.'-'.(trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($c->name)), '-') ?: 'azienda');
        foreach ($companies as $c) {
            $dir = $root($c);
            $counts = [];
            $add("{$dir}/azienda.json", json_encode(DB::table('companies')->where('id', $c->id)->first(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            foreach ($tables as $t) {
                $cols = Schema::getColumnListing($t);
                if (in_array('company_id', $cols, true)) {
                    $q = DB::table($t)->where('company_id', $c->id);
                } elseif (isset(self::CHILDREN[$t])) {
                    [$col, $parent] = self::CHILDREN[$t];
                    $q = DB::table($t)->whereIn($col, DB::table($parent)->where('company_id', $c->id)->select('id'));
                } else {
                    continue;
                }
                $lines = [];
                foreach ($q->orderBy(in_array('id', $cols, true) ? 'id' : $cols[0])->cursor() as $row) {
                    $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($lines) {
                    $add("{$dir}/dati/{$t}.ndjson", implode("\n", $lines)."\n");
                    $counts[$t] = count($lines);
                }
            }
            $n = 0;
            foreach (DB::table('documents')->where('company_id', $c->id)->orderBy('id')->get(['id', 'original_name', 'storage_path', 'sha256']) as $d) {
                if (Storage::disk('local')->exists($d->storage_path)) {
                    $add("{$dir}/documenti/{$d->id}_".preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $d->original_name), Storage::disk('local')->get($d->storage_path));
                    $n++;
                }
            }
            if ($c->logo_path && Storage::disk('local')->exists($c->logo_path)) {
                $add("{$dir}/logo.png", Storage::disk('local')->get($c->logo_path));
            }
            $sha[$c->id] = ['dir' => $dir, 'tabelle' => $counts, 'documenti' => $n];
        }
        $manifest = json_encode(['creato' => now()->toIso8601String(), 'da' => $actor->email, 'schema' => (string) DB::table('migrations')->orderByDesc('id')->value('migration'), 'aziende' => array_map(fn ($c) => ['id' => $c->id, 'denominazione' => $c->name, 'partita_iva' => $c->partita_iva, ...$sha[$c->id]], $companies)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $add('manifest.json', $manifest);
        $add('LEGGIMI.txt', 'Backup di Semplicon creato il '.now()->format('d/m/Y H:i')."\r\n\r\nContenuto: per ogni azienda tutte le tabelle dei dati (file .ndjson, un record JSON per riga), i documenti originali, il logo e il file azienda.json.\r\n"
            ."Non contiene account utente né password. Verifica l'integrità con:  sha256sum -c SHA256SUMS\r\nRipristino: in Semplicon, Account › «Ripristina da backup» (nuova azienda o sostituzione dei dati con la stessa partita IVA).\r\n");
        $zip->addFromString('SHA256SUMS', $sums);
        $zip->close();
        $total = hash('sha256', $sums);
        foreach ($companies as $c) {
            Audit::record('backup.created', $actor, $c, data: ['sha256' => $total, 'files' => $files, 'companies' => count($companies)]);
        }

        return ['path' => $path, 'filename' => 'semplicon-backup-'.now()->format('Y-m-d').'.zip', 'sha256' => $total, 'files' => $files + 1, 'companies' => count($companies)];
    }
}
