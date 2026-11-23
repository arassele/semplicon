<?php

namespace App\Domain\Archive;

use App\Domain\Audit;
use App\Domain\AuditChain;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Ripristino da un backup (CompanyBackup): verifica checksum e struttura, ricrea l'azienda oppure sostituisce i dati di una
 * azienda esistente con la stessa partita IVA (solo con password). Tutti gli identificativi vengono riassegnati e riscritti tramite le chiavi
 * esterne; i riferimenti agli utenti passano all'utente che esegue l'operazione o vengono meno; la catena di hash del registro delle attività viene risigillata.
 * Tutto avviene in una transazione; in caso di errore non resta nulla.
 */
final class CompanyRestore
{
    private const MAX_ZIP_BYTES = 150 * 1024 * 1024;

    private const MAX_UNPACKED = 600 * 1024 * 1024;

    private const MAX_ENTRIES = 30000;

    /** Tabelle che non vengono mai scritte da un backup. */
    private const SKIP = ['users', 'sessions', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'companies', 'ateco_codes', 'memberships'];

    /** Tabelle figlie senza company_id proprio. */
    private const CHILDREN = ['invoice_lines', 'invoice_vat_summaries', 'vat_registration_lines'];

    /** Colonne che rimandano a un'altra tabella senza essere chiavi esterne: tabella => [colonna => tabella di destinazione]. */
    private const IMPLICIT = ['vat_registrations' => ['active_invoice_id' => 'invoices']];

    /** Nome del modello nel registro delle attività (subject_type) => tabella. */
    private const SUBJECTS = ['Invoice' => 'invoices', 'VatRegistration' => 'vat_registrations', 'Document' => 'documents', 'VatSettlement' => 'vat_settlements', 'Partner' => 'partners', 'CatalogArticle' => 'catalog_articles',
        'CatalogSnippet' => 'catalog_snippets', 'CatalogUnit' => 'catalog_units', 'RecurringInvoice' => 'recurring_invoices', 'ViesCheck' => 'vies_checks', 'F24Draft' => 'f24_drafts', 'VatProfile' => 'vat_profiles', 'Account' => 'accounts',
        'NonVatEntry' => 'non_vat_entries', 'MoneyMovement' => 'money_movements', 'FiscalParameter' => 'fiscal_parameters'];

    /** @var list<string> */
    private array $written = [];

    /** @return list<array{company: string, vat: string, created: bool, tables: int, rows: int, documents: int}> */
    public function restore(User $actor, string $zipPath, string $mode, ?string $password): array
    {
        if (! in_array($mode, ['new', 'replace'], true)) {
            RuleViolation::fail(['mode' => 'Scegli se creare una nuova azienda o sostituire quella esistente.']);
        }
        if ($mode === 'replace') {
            $key = "restore:{$actor->id}";
            if (RateLimiter::tooManyAttempts($key, 5)) {
                RuleViolation::fail(['password' => 'Troppi tentativi. Riprova tra qualche minuto.']);
            }
            if (! Hash::check((string) $password, (string) $actor->password)) {
                RateLimiter::hit($key, 300);
                RuleViolation::fail(['password' => 'La password non è corretta.']);
            }
            RateLimiter::clear($key);
        }
        if (filesize($zipPath) > self::MAX_ZIP_BYTES) {
            RuleViolation::fail(['file' => 'Il file è troppo grande.']);
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            RuleViolation::fail(['file' => 'Il file non è un archivio ZIP valido.']);
        }
        try {
            $manifest = $this->verify($zip);
            $plans = [];
            foreach ((array) ($manifest['aziende'] ?? []) as $a) {
                $dir = (string) ($a['dir'] ?? '');
                $vat = (string) ($a['partita_iva'] ?? '');
                if (! preg_match('/^azienda-\d+-[a-z0-9-]*$/', $dir) || ! preg_match('/^\d{11}$/', $vat)) {
                    RuleViolation::fail(['file' => 'Il backup contiene un’azienda non valida.']);
                }
                $existing = Company::where('partita_iva', $vat)->first();
                if ($mode === 'new' && $existing) {
                    RuleViolation::fail(['mode' => "Esiste già un’azienda con la partita IVA {$vat}: usa «Sostituisci i dati dell’azienda esistente»."]);
                }
                if ($mode === 'replace') {
                    if (! $existing) {
                        RuleViolation::fail(['mode' => "Non esiste un’azienda con la partita IVA {$vat}: scegli «Nuova azienda»."]);
                    }
                    if (! $actor->canAccess($existing)) {
                        RuleViolation::fail(['mode' => "L’azienda con la partita IVA {$vat} appartiene ad altri utenti."]);
                    }
                }
                $plans[] = ['dir' => $dir, 'vat' => $vat, 'existing' => $existing];
            }
            if (! $plans) {
                RuleViolation::fail(['file' => 'Il backup non contiene aziende.']);
            }
            $out = [];
            try {
                DB::transaction(function () use ($zip, $plans, $actor, &$out, $manifest) {
                    foreach ($plans as $p) {
                        $out[] = $this->restoreOne($zip, $actor, $p['dir'], $p['existing'], $manifest);
                    }
                });
            } catch (Throwable $e) {
                foreach ($this->written as $f) {
                    Storage::disk('local')->delete($f);
                }
                throw $e;
            }

            return $out;
        } finally {
            $zip->close();
        }
    }

    /** Verifica struttura, percorsi e checksum SHA-256; restituisce il manifest. */
    private function verify(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            RuleViolation::fail(['file' => 'Il backup contiene troppi file.']);
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $n = (string) $st['name'];
            if (str_contains($n, '..') || str_starts_with($n, '/') || str_contains($n, '\\')) {
                RuleViolation::fail(['file' => 'Il backup contiene percorsi non validi.']);
            }
            $total += (int) $st['size'];
        }
        if ($total > self::MAX_UNPACKED) {
            RuleViolation::fail(['file' => 'Il backup decompresso è troppo grande.']);
        }
        $sums = $zip->getFromName('SHA256SUMS');
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if ($sums === false || ! is_array($manifest)) {
            RuleViolation::fail(['file' => 'Non è un backup di Semplicon (mancano manifest.json o SHA256SUMS).']);
        }
        $listed = [];
        foreach (array_filter(explode("\n", trim($sums))) as $line) {
            [$hash, $name] = array_pad(explode('  ', $line, 2), 2, '');
            $data = $zip->getFromName($name);
            if ($data === false || ! hash_equals($hash, hash('sha256', $data))) {
                RuleViolation::fail(['file' => "Il backup è alterato o danneggiato: la verifica di «{$name}» non riesce."]);
            }
            $listed[$name] = true;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if ($n !== 'SHA256SUMS' && ! isset($listed[$n]) && ! str_ends_with($n, '/')) {
                RuleViolation::fail(['file' => "Il backup contiene un file non elencato in SHA256SUMS: {$n}."]);
            }
        }

        return $manifest;
    }

    /** @return array{company: string, vat: string, created: bool, tables: int, rows: int, documents: int} */
    private function restoreOne(ZipArchive $zip, User $actor, string $dir, ?Company $existing, array $manifest): array
    {
        $row = json_decode((string) $zip->getFromName("{$dir}/azienda.json"), true);
        if (! is_array($row) || ! isset($row['partita_iva'])) {
            RuleViolation::fail(['file' => "Manca azienda.json in {$dir}."]);
        }
        $companyCols = Schema::getColumnListing('companies');
        $values = array_intersect_key($row, array_flip($companyCols));
        unset($values['id']);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (str_starts_with($n, "{$dir}/dati/") && str_ends_with($n, '.ndjson') && ! in_array(basename($n, '.ndjson'), self::SKIP, true)) {
                $files[basename($n, '.ndjson')] = $n;
            }
        }
        $tables = $this->order(array_keys($files));

        if ($existing) {
            $company = $existing;
            $this->purge($company, $tables);
            unset($values['created_by'], $values['partita_iva']);
            DB::table('companies')->where('id', $company->id)->update([...$values, 'logo_path' => null, 'updated_at' => now()]);
            $created = false;
        } else {
            $id = DB::table('companies')->insertGetId([...$values, 'name' => mb_substr((string) $row['name'].' (ripristinata)', 0, 255), 'created_by' => $actor->id, 'logo_path' => null]);
            $company = Company::findOrFail($id);
            Membership::create(['company_id' => $company->id, 'user_id' => $actor->id]);
            $created = true;
        }
        $cid = $company->id;

        $fk = [];
        $types = [];
        foreach ($tables as $t) {
            foreach (Schema::getForeignKeys($t) as $f) {
                if (count($f['columns']) === 1) {
                    $fk[$t][$f['columns'][0]] = $f['foreign_table'];
                }
            }
            foreach (Schema::getColumns($t) as $c) {
                $types[$t][$c['name']] = ['nullable' => (bool) $c['nullable'], 'bool' => in_array($c['type_name'], ['bool', 'boolean'], true) || $c['type'] === 'tinyint(1)'];
            }
        }
        $map = [];
        $deferred = [];
        $rows = 0;
        $docs = 0;
        foreach ($tables as $t) {
            foreach (preg_split('/\r?\n/', (string) $zip->getFromName($files[$t]), -1, PREG_SPLIT_NO_EMPTY) as $line) {
                $r = json_decode($line, true);
                if (! is_array($r)) {
                    RuleViolation::fail(['file' => "Riga non valida in {$t}."]);
                }
                $old = $r['id'] ?? null;
                $new = [];
                foreach ($r as $col => $val) {
                    if ($col === 'id') {
                        continue;
                    }
                    if (! isset($types[$t][$col])) {
                        RuleViolation::fail(['file' => "Il backup non è compatibile con questa versione: colonna sconosciuta {$t}.{$col}."]);
                    }
                    if ($col === 'company_id') {
                        $new[$col] = $cid;
                    } elseif (isset(self::IMPLICIT[$t][$col]) && $val !== null) {
                        // Riferimento senza chiave esterna (es. registrazione attiva di una fattura)
                        $new[$col] = $map[self::IMPLICIT[$t][$col]][$val] ?? null;
                    } elseif (isset($fk[$t][$col]) && $val !== null) {
                        $ft = $fk[$t][$col];
                        if ($ft === 'users') {
                            $new[$col] = $types[$t][$col]['nullable'] ? null : $actor->id;
                        } elseif ($ft === 'companies') {
                            $new[$col] = $cid;
                        } elseif ($ft === $t) {
                            $new[$col] = null;
                            $deferred[] = [$t, $old, $col, $val];
                        } elseif (isset($map[$ft][$val])) {
                            $new[$col] = $map[$ft][$val];
                        } elseif ($types[$t][$col]['nullable']) {
                            $new[$col] = null;
                        } else {
                            RuleViolation::fail(['file' => "Riferimento mancante nel backup: {$t}.{$col} = {$val}."]);
                        }
                    } else {
                        $new[$col] = $types[$t][$col]['bool'] && $val !== null ? (bool) $val : $val;
                    }
                }
                if ($t === 'documents') {
                    $new['storage_path'] = "documents/{$cid}/".substr((string) $r['sha256'], 0, 2).'/'.$r['sha256'].'.'.$r['kind'];
                    $name = $this->findDocument($zip, $dir, (int) $old);
                    if ($name !== null) {
                        Storage::disk('local')->put($new['storage_path'], (string) $zip->getFromName($name));
                        $this->written[] = $new['storage_path'];
                        $docs++;
                    }
                }
                $id = DB::table($t)->insertGetId($new);
                if ($old !== null) {
                    $map[$t][$old] = $id;
                }
                $rows++;
            }
        }
        foreach ($deferred as [$t, $old, $col, $val]) {
            if (isset($map[$t][$old], $map[$t][$val])) {
                DB::table($t)->where('id', $map[$t][$old])->update([$col => $map[$t][$val]]);
            }
        }
        // Registro delle attività: riscrivere i riferimenti ai record (subject_id) con i nuovi identificativi
        foreach (DB::table('audit_events')->where('company_id', $cid)->whereNotNull('subject_id')->get(['id', 'subject_type', 'subject_id']) as $a) {
            $target = self::SUBJECTS[$a->subject_type] ?? null;
            $newId = $a->subject_type === 'Company' ? $cid : ($target ? ($map[$target][$a->subject_id] ?? null) : null);
            DB::table('audit_events')->where('id', $a->id)->update(['subject_id' => $newId]);
        }
        if ($zip->getFromName("{$dir}/logo.png") !== false) {
            $path = "logos/{$cid}/logo.png";
            Storage::disk('local')->put($path, (string) $zip->getFromName("{$dir}/logo.png"));
            $this->written[] = $path;
            DB::table('companies')->where('id', $cid)->update(['logo_path' => $path]);
        }
        AuditChain::reseal($company);
        Audit::record('backup.restored', $actor, $company->fresh(), data: ['mode' => $created ? 'new' : 'replace', 'tables' => count($tables), 'rows' => $rows, 'documents' => $docs, 'backup_created' => $manifest['creato'] ?? null]);

        return ['company' => (string) $company->fresh()->name, 'vat' => (string) $company->partita_iva, 'created' => $created, 'tables' => count($tables), 'rows' => $rows, 'documents' => $docs];
    }

    private function findDocument(ZipArchive $zip, string $dir, int $id): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (str_starts_with($n, "{$dir}/documenti/{$id}_")) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Tabelle ammesse in ordine di inserimento (padri prima dei figli) secondo le chiavi esterne.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function order(array $names): array
    {
        $existing = array_map(fn ($t) => $t['name'], Schema::getTables());
        foreach ($names as $t) {
            $ok = in_array($t, $existing, true) && ! in_array($t, self::SKIP, true) && (in_array($t, self::CHILDREN, true) || in_array('company_id', Schema::getColumnListing($t), true));
            if (! $ok) {
                RuleViolation::fail(['file' => "Il backup contiene una tabella non ammessa: {$t}."]);
            }
        }
        $deps = [];
        foreach ($names as $t) {
            $deps[$t] = array_values(array_unique(array_filter(array_map(fn ($f) => $f['foreign_table'], Schema::getForeignKeys($t)), fn ($p) => $p !== $t && in_array($p, $names, true))));
        }
        $sorted = [];
        while ($deps) {
            $ready = array_keys(array_filter($deps, fn ($d) => ! array_diff($d, $sorted)));
            if (! $ready) {
                RuleViolation::fail(['file' => 'Dipendenze circolari tra le tabelle: backup non ripristinabile.']);
            }
            foreach ($ready as $t) {
                $sorted[] = $t;
                unset($deps[$t]);
            }
        }

        return $sorted;
    }

    /** Rimuove tutti i dati di un'azienda (prima di «Sostituisci»): prima i figli, sciogliere prima i riferimenti a sé stessi, eliminare i file. */
    private function purge(Company $company, array $restoreTables): void
    {
        $cid = $company->id;
        $all = array_values(array_filter(array_map(fn ($t) => $t['name'], Schema::getTables()), fn ($t) => ! in_array($t, self::SKIP, true) && in_array('company_id', Schema::getColumnListing($t), true)));
        $paths = DB::table('documents')->where('company_id', $cid)->pluck('storage_path')->all();
        $logo = DB::table('companies')->where('id', $cid)->value('logo_path');
        $order = array_reverse($this->order($all));
        foreach ($order as $t) {
            foreach (Schema::getForeignKeys($t) as $f) {
                if ($f['foreign_table'] === $t && count($f['columns']) === 1) {
                    DB::table($t)->where('company_id', $cid)->update([$f['columns'][0] => null]);
                }
            }
        }
        foreach ($order as $t) {
            DB::table($t)->where('company_id', $cid)->delete();
        }
        foreach ($paths as $p) {
            Storage::disk('local')->delete($p);
        }
        if ($logo) {
            Storage::disk('local')->delete($logo);
        }
    }
}
