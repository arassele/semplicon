<?php

namespace App\Domain\Cespiti;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Asset;
use App\Models\AssetDepreciation;
use App\Models\AssetDepreciationRun;
use App\Models\AssetMovement;
use App\Models\Company;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Calcolo ammortamenti per anno: simulazione (nulla salvato) → «calcolato» (quote definitive, esecuzione ricalcolabile) → «contabilizzato» (Prima nota,
 * dopo conferma esplicita) → «stornato» (registrazione inversa, quote di nuovo libere). Un anno ha al massimo un'esecuzione valida.
 */
final class DepreciationService
{
    public function __construct(private JournalService $journal) {}

    /** Cespiti con quota nell'anno (attivi, non venduti/dismessi prima dell'inizio dell'anno). */
    private function candidates(Company $c, int $year)
    {
        return $c->assets()->with(['category.rate', 'rates', 'opening', 'company'])->where('kind', '!=', 'non_ammortizzabile')
            ->whereDate('purchase_date', '<=', "{$year}-12-31")->orderBy('number')->get();
    }

    /**
     * Opzioni dell'esecuzione (decisione n. 81): quota ridotta in % della quota ordinaria per tutti i materiali, sovrascrivibile per categoria
     * (vuoto = come l'esecuzione, 100 = ordinaria). Mai per gli immateriali. Restituzione in centesimi.
     *
     * @return array{reduced_pct: ?int, categories: array<int, int>}
     */
    public static function options(array $d): array
    {
        $e = [];
        $p = function ($v, string $key) use (&$e): ?int {
            $v = trim(str_replace(',', '.', (string) ($v ?? '')));
            if ($v === '') {
                return null;
            }
            if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v) || (float) $v <= 0 || (float) $v > 100) {
                $e[$key] = 'Percentuale della quota ordinaria tra 0,01 e 100.';

                return null;
            }

            return Decimal::toMinor($v);
        };
        $o = ['reduced_pct' => $p($d['reduced_pct'] ?? null, 'reduced_pct'), 'categories' => []];
        foreach ((array) ($d['categories'] ?? []) as $id => $v) {
            $x = $p($v, "categories.{$id}");
            if ($x !== null) {
                $o['categories'][(int) $id] = $x;
            }
        }
        $e && RuleViolation::fail($e);

        return $o;
    }

    /** Percentuale della quota ridotta per un cespite (null = ordinaria). */
    public static function reduceFor(Asset $a, array $o): ?int
    {
        if ($a->kind !== 'materiale') {
            return null;
        }
        $v = $o['categories'][$a->asset_category_id] ?? $o['reduced_pct'] ?? null;

        return $v !== null && $v < 10000 ? $v : null;
    }

    /** Simulazione: righe per cespite per l'anno, con controlli (anni precedenti aperti). */
    public function simulate(Company $c, int $year, array $options = ['reduced_pct' => null, 'categories' => []]): array
    {
        $rows = [];
        $issues = [];
        foreach ($this->candidates($c, $year) as $a) {
            $in = AssetLedger::input($a);
            if (($red = self::reduceFor($a, $options)) !== null) {
                $in['reduce'][$year] = $red;
            }
            if ($in['start_year'] > $year) {
                continue;
            }
            $plan = DepreciationEngine::plan($in, $in['start_year'], $year);
            foreach ($plan as $r) {
                if ($r['year'] < $year && ! $r['fixed'] && $r['civil_amount'] + $r['fiscal_amount'] > 0) {
                    $issues[$r['year']][] = $a->code;
                }
            }
            $row = collect($plan)->firstWhere('year', $year);
            if ($row) {
                $rows[] = ['asset' => $a, 'row' => $row];
            }
        }
        $blocking = [];
        foreach ($issues as $y => $codes) {
            $blocking[] = "Esercizio {$y} non ancora calcolato per ".count($codes).' cespiti ('.implode(', ', array_slice(array_unique($codes), 0, 5)).'): calcola prima gli esercizi precedenti.';
        }

        return ['year' => $year, 'rows' => $rows, 'blocking' => $blocking, 'run' => $c->assetDepreciationRuns()->where('year', $year)->whereIn('status', ['calcolato', 'contabilizzato'])->latest('id')->first(),
            'closed' => FiscalYears::isClosed($c, $year)];
    }

    /** Consolidare le quote (esecuzione «calcolato»). Un'esecuzione presente e non contabilizzata viene sostituita. */
    public function calculate(Company $c, User $actor, int $year, array $options = ['reduced_pct' => null, 'categories' => []]): AssetDepreciationRun
    {
        FiscalYears::assertOpen($c, $year, field: 'year');

        return DB::transaction(function () use ($c, $actor, $year, $options) {
            Company::whereKey($c->id)->lockForUpdate()->first();
            $existing = $c->assetDepreciationRuns()->where('year', $year)->whereIn('status', ['calcolato', 'contabilizzato'])->first();
            if ($existing?->status === 'contabilizzato') {
                RuleViolation::fail(['year' => "Ammortamenti {$year} già contabilizzati: stornali prima di ricalcolare."]);
            }
            if ($existing) {
                AssetDepreciation::where('asset_depreciation_run_id', $existing->id)->delete();
                $existing->delete();
            }
            $sim = $this->simulate($c, $year, $options);
            if ($sim['blocking']) {
                RuleViolation::fail(['year' => implode(' ', $sim['blocking'])]);
            }
            $run = AssetDepreciationRun::create(['company_id' => $c->id, 'year' => $year, 'status' => 'calcolato', 'calculated_by' => $actor->id, 'calculated_at' => now(),
                'options' => ['reduced_pct' => $options['reduced_pct'] !== null ? Decimal::fromMinor($options['reduced_pct']) : null,
                    'categories' => array_map(fn ($v) => Decimal::fromMinor($v), $options['categories'])]]);
            $tc = $tf = 0;
            foreach ($sim['rows'] as ['asset' => $a, 'row' => $r]) {
                if ($r['fixed']) {
                    continue; // storico/importato
                }
                AssetDepreciation::create(['company_id' => $c->id, 'asset_id' => $a->id, 'year' => $year, 'asset_depreciation_run_id' => $run->id, 'method' => $r['method'],
                    'civil_rate' => Decimal::fromMinor($r['civil_rate']), 'fiscal_rate' => Decimal::fromMinor($r['fiscal_rate']), ...self::money($r), 'days' => $r['days'],
                    'status' => 'calcolato', 'notes' => self::notes($r), 'created_by' => $actor->id]);
                $tc += $r['civil_amount'];
                $tf += $r['fiscal_amount'];
                app(AssetLedger::class)->refreshStatus($a);
            }
            $run->update(['total_civil' => Decimal::fromMinor($tc), 'total_fiscal' => Decimal::fromMinor($tf)]);
            Audit::record('depreciation.calculated', $actor, $c, $run, data: ['year' => $year, 'assets' => count($sim['rows']), 'civil' => Decimal::fromMinor($tc), 'fiscal' => Decimal::fromMinor($tf), 'options' => $run->options]);

            return $run;
        });
    }

    private static function notes(array $r): ?string
    {
        $n = $r['warnings'];
        if (isset($r['reduced_pct'])) {
            array_unshift($n, 'Quota ridotta al '.str_replace('.', ',', Decimal::fromMinor($r['reduced_pct'])).'% della quota ordinaria.');
        }

        return $n ? implode(' ', $n) : null;
    }

    public static function money(array $r): array
    {
        $o = [];
        foreach (['base_civil', 'base_fiscal', 'fund_civil_start', 'civil_amount', 'fund_civil_end', 'fund_fiscal_start', 'fiscal_limit', 'fiscal_amount', 'fund_fiscal_end', 'nondeductible', 'extra_deduction'] as $k) {
            $o[$k] = Decimal::fromMinor($r[$k] ?? 0);
        }

        return $o;
    }

    /** Contabilizzazione dopo conferma: per coppia di conti Dare Ammortamento / Avere Fondo, movimento «ammortamento» per cespite. */
    public function post(Company $c, User $actor, AssetDepreciationRun $run): AssetDepreciationRun
    {
        FiscalYears::assertOpen($c, $run->year, field: 'year');

        return DB::transaction(function () use ($c, $actor, $run) {
            $run = AssetDepreciationRun::whereKey($run->id)->lockForUpdate()->first();
            if ($run->status !== 'calcolato') {
                RuleViolation::fail(['run' => 'Solo un calcolo “calcolato” si può contabilizzare (nessuna doppia contabilizzazione).']);
            }
            $pairs = [];
            $rows = AssetDepreciation::where('asset_depreciation_run_id', $run->id)->with('asset.category')->get();
            foreach ($rows as $d) {
                $amt = AssetLedger::m($d->civil_amount);
                if ($amt === 0) {
                    continue;
                }
                $k = $d->asset->account('expense').'-'.$d->asset->account('fund');
                $pairs[$k] ??= ['expense' => $d->asset->account('expense'), 'fund' => $d->asset->account('fund'), 'amount' => 0, 'codes' => []];
                $pairs[$k]['amount'] += $amt;
                $pairs[$k]['codes'][] = $d->asset->code;
            }
            $lines = [];
            foreach ($pairs as $p) {
                $lines[] = ['account_id' => $p['expense'], 'dare' => $p['amount'], 'description' => 'Ammortamento '.$run->year.' ('.count($p['codes']).' cespiti)'];
                $lines[] = ['account_id' => $p['fund'], 'avere' => $p['amount'], 'description' => 'Fondo ammortamento '.$run->year];
            }
            $entry = $lines ? $this->journal->post($c, $actor, 'run:'.$run->id, "{$run->year}-12-31", "Ammortamenti esercizio {$run->year}", $lines) : null;
            foreach ($rows as $d) {
                $d->update(['status' => 'contabilizzato']);
                if (AssetLedger::m($d->civil_amount) !== 0) {
                    AssetMovement::create(['company_id' => $c->id, 'asset_id' => $d->asset_id, 'type' => 'ammortamento', 'movement_date' => "{$run->year}-12-31", 'year' => $run->year,
                        'amount' => $d->civil_amount, 'fiscal_amount' => $d->fiscal_amount, 'causale' => "Ammortamento {$run->year}", 'journal_entry_id' => $entry?->id, 'created_by' => $actor->id,
                        'data' => ['run' => $run->id]]);
                }
            }
            $run->update(['status' => 'contabilizzato', 'journal_entry_id' => $entry?->id, 'posted_by' => $actor->id, 'posted_at' => now()]);
            Audit::record('depreciation.posted', $actor, $c, $run, data: ['year' => $run->year, 'entry' => $entry ? "{$entry->number}/{$entry->year}" : null]);

            return $run;
        });
    }

    /** Storno: registrazione inversa, quote «stornato», esecuzione «stornato». Poi si può ricalcolare. */
    public function reverse(Company $c, User $actor, AssetDepreciationRun $run, string $reason): AssetDepreciationRun
    {
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo dello storno.']);
        }
        FiscalYears::assertOpen($c, $run->year, field: 'year');

        return DB::transaction(function () use ($c, $actor, $run, $reason) {
            $run = AssetDepreciationRun::whereKey($run->id)->lockForUpdate()->first();
            if (! in_array($run->status, ['calcolato', 'contabilizzato'], true)) {
                RuleViolation::fail(['run' => 'Il calcolo è già stornato.']);
            }
            $later = $c->assetDepreciationRuns()->where('year', '>', $run->year)->whereIn('status', ['calcolato', 'contabilizzato'])->exists();
            if ($later) {
                RuleViolation::fail(['run' => 'Esistono calcoli di esercizi successivi: stornali prima (dal più recente).']);
            }
            $sale = AssetMovement::where('company_id', $c->id)->where('status', 'registrato')->whereIn('type', ['alienazione_totale', 'alienazione_parziale', 'eliminazione'])->where('year', '>', $run->year)->exists();
            if ($sale) {
                RuleViolation::fail(['run' => 'Esistono alienazioni o eliminazioni successive basate su questi fondi: stornale prima.']);
            }
            $rev = $run->journal_entry_id ? $this->journal->reverse($c, $actor, $run->journal()->first(), now()->toDateString() > "{$run->year}-12-31" ? "{$run->year}-12-31" : now()->toDateString(), $reason) : null;
            AssetDepreciation::where('asset_depreciation_run_id', $run->id)->update(['status' => 'stornato']);
            AssetMovement::where('company_id', $c->id)->where('type', 'ammortamento')->where('year', $run->year)->where('status', 'registrato')->get()
                ->filter(fn ($m) => ($m->data['run'] ?? null) === $run->id)->each(fn ($m) => $m->update(['status' => 'stornato']));
            $run->update(['status' => 'stornato', 'reversal_entry_id' => $rev?->id, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reason' => trim($reason)]);
            foreach (Asset::whereIn('id', AssetDepreciation::where('asset_depreciation_run_id', $run->id)->pluck('asset_id'))->get() as $a) {
                app(AssetLedger::class)->refreshStatus($a);
            }
            Audit::record('depreciation.reversed', $actor, $c, $run, $reason, ['year' => $run->year]);

            return $run;
        });
    }
}
