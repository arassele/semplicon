<?php

namespace App\Domain\Cespiti;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\AssetDepreciation;
use App\Models\AssetImportJob;
use App\Models\AssetOpeningBalance;
use App\Models\AssetRate;
use App\Models\Company;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Importazione cespiti e progressivi da altri programmi (CSV; interfaccia per una futura API tramite `rows`).
 * Modalità A «saldi»: una riga per cespite con fondi all'ultimo anno ammortizzato; modalità B «storico»: righe per cespite e anno con quote.
 * Flusso: file → anteprima con associazione delle colonne e controlli → importazione. Non vengono generate registrazioni in Prima nota (i saldi sono già nella contabilità).
 */
final class AssetImportService
{
    public const FIELDS = [
        'codice' => ['codice', 'code', 'cod', 'codice cespite', 'cespite'], 'descrizione' => ['descrizione', 'description', 'desc', 'bene'], 'categoria' => ['categoria', 'category', 'cat', 'codice categoria'],
        'data_acquisto' => ['data acquisto', 'data_acquisto', 'acquisto', 'purchase_date'], 'data_entrata_funzione' => ['data entrata in funzione', 'entrata in funzione', 'data_entrata_funzione', 'in_service_date'],
        'costo_storico' => ['costo storico', 'costo', 'costo_storico', 'valore originario', 'cost'], 'incrementi' => ['incrementi'], 'decrementi' => ['decrementi'], 'rivalutazioni' => ['rivalutazioni'],
        'aliquota_civilistica' => ['aliquota civilistica', 'aliquota_civilistica', 'aliquota civ', 'aliquota'], 'aliquota_fiscale' => ['aliquota fiscale', 'aliquota_fiscale', 'aliquota fisc'],
        'fondo_civilistico' => ['fondo civilistico', 'fondo_civilistico', 'fondo', 'fondo ammortamento'], 'fondo_fiscale' => ['fondo fiscale', 'fondo_fiscale'],
        'ultimo_esercizio' => ['ultimo esercizio', 'ultimo esercizio ammortizzato', 'ultimo_esercizio'], 'residuo' => ['residuo', 'valore residuo'], 'stato' => ['stato', 'status'],
        'anno' => ['anno', 'esercizio', 'year'], 'quota_civilistica' => ['quota civilistica', 'quota_civilistica', 'quota'], 'quota_fiscale' => ['quota fiscale', 'quota_fiscale'],
    ];

    public static function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if (! $lines || $lines[0] === '') {
            RuleViolation::fail(['file' => 'Il file è vuoto.']);
        }
        $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $head = str_getcsv(array_shift($lines), $sep, '"', '');
        $rows = [];
        foreach ($lines as $l) {
            if (trim($l) === '') {
                continue;
            }
            $rows[] = str_getcsv($l, $sep, '"', '');
        }

        return [array_map(fn ($h) => trim((string) $h), $head), $rows];
    }

    public static function autoMap(array $head): array
    {
        $map = [];
        foreach ($head as $i => $h) {
            $k = mb_strtolower(trim($h));
            foreach (self::FIELDS as $f => $syn) {
                if (! isset($map[$f]) && in_array($k, $syn, true)) {
                    $map[$f] = $i;
                }
            }
        }

        return $map;
    }

    private static function num(?string $v): ?int
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $v) || str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        }
        if (! preg_match('/^-?\d{1,12}(\.\d{1,2})?$/', $v)) {
            return PHP_INT_MIN;
        }

        return Decimal::toMinor($v);
    }

    private static function date(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $v;
        }

        return 'invalid';
    }

    /** Anteprima: leggere il file, associare le colonne, controllare; job «anteprima» con dati grezzi e rapporto. */
    public function preview(Company $c, User $actor, string $filename, string $content, string $mode, ?array $mapping = null, ?AssetImportJob $job = null): AssetImportJob
    {
        if (! in_array($mode, ['saldi', 'storico'], true)) {
            RuleViolation::fail(['mode' => 'Modalità non valida.']);
        }
        [$head, $raw] = self::parse($content);
        $map = $mapping ? array_map('intval', array_filter($mapping, fn ($v) => $v !== null && $v !== '')) : self::autoMap($head);
        $report = $this->validate($c, $head, $raw, $map, $mode);
        $data = ['company_id' => $c->id, 'created_by' => $actor->id, 'filename' => mb_substr($filename, 0, 255), 'mode' => $mode, 'status' => 'anteprima', 'mapping' => $map, 'report' => [...$report, 'head' => $head, 'raw' => $raw], 'rows' => count($raw)];
        $job ? $job->update($data) : $job = AssetImportJob::create($data);
        Audit::record('asset_import.previewed', $actor, $c, $job, data: ['file' => $filename, 'rows' => count($raw), 'errors' => $report['error_count']]);

        return $job;
    }

    public function validate(Company $c, array $head, array $raw, array $map, string $mode): array
    {
        $need = $mode === 'saldi' ? ['codice', 'descrizione', 'categoria', 'data_acquisto', 'costo_storico', 'fondo_civilistico', 'ultimo_esercizio'] : ['codice', 'descrizione', 'categoria', 'data_acquisto', 'costo_storico', 'anno', 'quota_civilistica'];
        $missing = array_values(array_diff($need, array_keys($map)));
        $cats = $c->assetCategories()->get()->keyBy('code');
        $existing = $c->assets()->pluck('code')->flip();
        $get = fn (array $r, string $f) => isset($map[$f]) ? ($r[$map[$f]] ?? null) : null;
        $assets = [];
        foreach ($raw as $i => $r) {
            $line = $i + 2;
            $e = [];
            $w = [];
            $code = trim((string) $get($r, 'codice'));
            $x = ['line' => $line, 'codice' => $code, 'descrizione' => trim((string) $get($r, 'descrizione')), 'categoria' => trim((string) $get($r, 'categoria'))];
            foreach (['data_acquisto', 'data_entrata_funzione'] as $f) {
                $x[$f] = self::date($get($r, $f));
                if ($x[$f] === 'invalid') {
                    $e[] = "Data non valida ({$f}).";
                }
            }
            foreach (['costo_storico', 'incrementi', 'decrementi', 'rivalutazioni', 'fondo_civilistico', 'fondo_fiscale', 'residuo', 'quota_civilistica', 'quota_fiscale', 'aliquota_civilistica', 'aliquota_fiscale'] as $f) {
                $x[$f] = self::num($get($r, $f));
                if ($x[$f] === PHP_INT_MIN) {
                    $e[] = "Importo non valido ({$f}).";
                    $x[$f] = null;
                }
            }
            $x['ultimo_esercizio'] = ($v = trim((string) $get($r, 'ultimo_esercizio'))) !== '' ? (int) $v : null;
            $x['anno'] = ($v = trim((string) $get($r, 'anno'))) !== '' ? (int) $v : null;
            $x['stato'] = mb_strtolower(trim((string) $get($r, 'stato')));
            if ($code === '') {
                $e[] = 'Codice mancante.';
            } elseif (isset($existing[$code])) {
                $e[] = 'Codice già presente in Semplicon: il cespite esistente non viene sovrascritto.';
            }
            if ($x['descrizione'] === '') {
                $e[] = 'Descrizione mancante.';
            }
            if (! isset($cats[$x['categoria']])) {
                $e[] = 'Categoria “'.$x['categoria'].'” inesistente: creala prima in Categorie e aliquote (codice).';
            } elseif ($x['aliquota_civilistica'] === null && $cats[$x['categoria']]->civil_rate === null && $cats[$x['categoria']]->fiscal_rate === null) {
                $w[] = 'Aliquota mancante nel file e nella categoria.';
            }
            if (! $x['data_acquisto']) {
                $e[] = 'Data di acquisto mancante.';
            }
            if (($x['costo_storico'] ?? 0) <= 0) {
                $e[] = 'Costo storico mancante o non positivo.';
            }
            if ($x['data_entrata_funzione'] && $x['data_acquisto'] && $x['data_entrata_funzione'] !== 'invalid' && $x['data_entrata_funzione'] < $x['data_acquisto']) {
                $e[] = 'Entrata in funzione precedente all’acquisto.';
            }
            if ($mode === 'saldi') {
                $cost = ($x['costo_storico'] ?? 0) + ($x['incrementi'] ?? 0) - ($x['decrementi'] ?? 0) + ($x['rivalutazioni'] ?? 0);
                if (($x['fondo_civilistico'] ?? 0) > $cost) {
                    $e[] = 'Fondo superiore al costo.';
                }
                if ($x['residuo'] !== null && $cost - ($x['fondo_civilistico'] ?? 0) !== $x['residuo']) {
                    $e[] = 'Quadratura: costo + incrementi − decrementi + rivalutazioni − fondo ('.Decimal::fromMinor($cost - ($x['fondo_civilistico'] ?? 0)).') ≠ residuo ('.Decimal::fromMinor($x['residuo']).').';
                }
                if (! $x['ultimo_esercizio'] || ($x['data_acquisto'] && $x['data_acquisto'] !== 'invalid' && $x['ultimo_esercizio'] < (int) substr($x['data_acquisto'], 0, 4))) {
                    $e[] = 'Ultimo esercizio ammortizzato mancante o precedente all’acquisto.';
                }
            } elseif (! $x['anno'] || $x['quota_civilistica'] === null) {
                $e[] = 'Anno e quota civilistica obbligatori nello storico annuale.';
            }
            $x['errors'] = $e;
            $x['warnings'] = $w;
            $assets[$code][] = $x;
        }
        $rows = [];
        foreach ($assets as $code => $list) {
            if ($mode === 'saldi' && count($list) > 1) {
                foreach ($list as &$x) {
                    $x['errors'][] = 'Codice duplicato nel file.';
                }
                unset($x);
            }
            if ($mode === 'storico') {
                $years = array_column($list, 'anno');
                if (count($years) !== count(array_unique($years))) {
                    $list[0]['errors'][] = 'Esercizio ripetuto per lo stesso cespite.';
                }
                sort($years);
                if ($years && $years !== range($years[0], end($years))) {
                    $list[0]['errors'][] = 'Esercizi non consecutivi nello storico.';
                }
                $cum = 0;
                $cost = $list[0]['costo_storico'] ?? 0;
                foreach (collect($list)->sortBy('anno') as $x) {
                    $cum += $x['quota_civilistica'] ?? 0;
                }
                if ($cum > $cost) {
                    $list[0]['errors'][] = 'La somma delle quote ('.Decimal::fromMinor($cum).') supera il costo.';
                }
            }
            array_push($rows, ...$list);
        }
        $errors = array_sum(array_map(fn ($x) => count($x['errors']), $rows));

        return ['missing' => $missing, 'rows' => $rows, 'error_count' => $errors + count($missing), 'warning_count' => array_sum(array_map(fn ($x) => count($x['warnings']), $rows)),
            'assets' => count($assets)];
    }

    /** Importazione delle righe valide (in caso di errori solo con `only_valid`). */
    public function import(Company $c, User $actor, AssetImportJob $job, bool $onlyValid = false): AssetImportJob
    {
        if ($job->status !== 'anteprima') {
            RuleViolation::fail(['job' => 'Importazione già eseguita o annullata.']);
        }
        $rep = $job->report;
        if ($rep['missing']) {
            RuleViolation::fail(['job' => 'Colonne obbligatorie non assegnate: '.implode(', ', $rep['missing']).'.']);
        }
        if ($rep['error_count'] && ! $onlyValid) {
            RuleViolation::fail(['job' => "Ci sono {$rep['error_count']} errori: correggi il file o importa solo le righe valide."]);
        }

        return DB::transaction(function () use ($c, $actor, $job, $rep) {
            $byCode = collect($rep['rows'])->groupBy('codice');
            $done = 0;
            $skipped = [];
            $svc = app(AssetService::class);
            $cats = $c->assetCategories()->get()->keyBy('code');
            foreach ($byCode as $code => $list) {
                if ($list->contains(fn ($x) => $x['errors'])) {
                    $skipped[] = $code;

                    continue;
                }
                $first = $list->sortBy('anno')->first();
                $cat = $cats[$first['categoria']];
                $a = $svc->save($c, $actor, ['asset_category_id' => $cat->id, 'code' => $code, 'description' => $first['descrizione'], 'purchase_date' => $first['data_acquisto'],
                    'in_service_date' => $first['data_entrata_funzione'] ?: $first['data_acquisto'], 'original_cost' => Decimal::fromMinor($first['costo_storico'])]);
                $civ = $first['aliquota_civilistica'];
                $fis = $first['aliquota_fiscale'] ?? $civ;
                if ($job->mode === 'saldi') {
                    AssetOpeningBalance::create(['asset_id' => $a->id, 'mode' => 'saldi', 'last_year' => $first['ultimo_esercizio'], 'fund_civil' => Decimal::fromMinor($first['fondo_civilistico'] ?? 0),
                        'fund_fiscal' => Decimal::fromMinor($first['fondo_fiscale'] ?? $first['fondo_civilistico'] ?? 0), 'increments' => Decimal::fromMinor($first['incrementi'] ?? 0),
                        'decrements' => Decimal::fromMinor($first['decrementi'] ?? 0), 'revaluations' => Decimal::fromMinor($first['rivalutazioni'] ?? 0), 'import_job_id' => $job->id, 'created_by' => $actor->id]);
                    $from = $first['ultimo_esercizio'] + 1;
                } else {
                    AssetOpeningBalance::create(['asset_id' => $a->id, 'mode' => 'storico', 'last_year' => (int) $list->max('anno'), 'import_job_id' => $job->id, 'created_by' => $actor->id]);
                    $fc = $ff = 0;
                    $cost = $first['costo_storico'];
                    foreach ($list->sortBy('anno') as $x) {
                        $qc = $x['quota_civilistica'];
                        $qf = $x['quota_fiscale'] ?? $qc;
                        AssetDepreciation::create(['company_id' => $c->id, 'asset_id' => $a->id, 'year' => $x['anno'], 'method' => 'storico', 'civil_rate' => $x['aliquota_civilistica'] !== null ? Decimal::fromMinor($x['aliquota_civilistica']) : null,
                            'fiscal_rate' => $x['aliquota_fiscale'] !== null ? Decimal::fromMinor($x['aliquota_fiscale']) : null, 'base_civil' => Decimal::fromMinor($cost), 'base_fiscal' => Decimal::fromMinor($cost),
                            'fund_civil_start' => Decimal::fromMinor($fc), 'civil_amount' => Decimal::fromMinor($qc), 'fund_civil_end' => Decimal::fromMinor($fc + $qc), 'fund_fiscal_start' => Decimal::fromMinor($ff),
                            'fiscal_limit' => Decimal::fromMinor($qf), 'fiscal_amount' => Decimal::fromMinor($qf), 'fund_fiscal_end' => Decimal::fromMinor($ff + $qf), 'nondeductible' => Decimal::fromMinor(max(0, $qc - $qf)),
                            'status' => 'storico', 'notes' => 'Importato da '.$job->filename, 'created_by' => $actor->id]);
                        $fc += $qc;
                        $ff += $qf;
                    }
                    $from = (int) $list->max('anno') + 1;
                }
                if ($civ !== null && (Decimal::fromMinor($civ) !== (string) ($cat->civil_rate ?? $cat->fiscal_rate) || Decimal::fromMinor($fis) !== (string) $cat->fiscal_rate)) {
                    AssetRate::create(['asset_id' => $a->id, 'from_year' => $from, 'civil_rate' => Decimal::fromMinor($civ), 'fiscal_rate' => Decimal::fromMinor($fis), 'reason' => 'Aliquote importate da '.$job->filename, 'created_by' => $actor->id]);
                }
                if (in_array($first['stato'], ['sospeso'], true)) {
                    $a->update(['excluded' => true, 'excluded_reason' => 'Importato come sospeso']);
                }
                app(AssetLedger::class)->refreshStatus($a);
                $done++;
            }
            $job->update(['status' => 'importato', 'imported' => $done, 'report' => [...$job->report, 'skipped' => $skipped, 'imported_at' => now()->toIso8601String()]]);
            Audit::record('asset_import.imported', $actor, $c, $job, data: ['imported' => $done, 'skipped' => count($skipped), 'mode' => $job->mode]);

            return $job;
        });
    }
}
