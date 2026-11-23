<?php

namespace App\Domain\Cespiti;

use App\Models\Asset;
use App\Models\AssetCategoryRate;
use App\Support\Decimal;

/**
 * Ponte database → motore di ammortamento: valori, aliquote per anno (cespite > categoria da anno > categoria > D.M.), eventi dai movimenti,
 * anni consolidati, saldi di apertura. Fornisce piano, situazione a una data e stato del cespite.
 */
final class AssetLedger
{
    public static function m(?string $v): int
    {
        return $v === null || $v === '' ? 0 : Decimal::toMinor((string) $v);
    }

    public static function h(?string $v): int
    {
        return self::m($v); // percentuale con 2 decimali = centesimi
    }

    public static function costCivil(Asset $a): int
    {
        return self::m($a->original_cost) + self::m($a->accessory_costs) + self::m($a->nondeductible_vat) - ($a->grant_treatment === 'riduzione' ? self::m($a->grants) : 0);
    }

    public static function costFiscal(Asset $a): int
    {
        return $a->fiscal_cost !== null ? self::m($a->fiscal_cost) : self::costCivil($a);
    }

    public static function startYear(Asset $a): int
    {
        return (int) ($a->depreciation_start ?? $a->in_service_date ?? $a->purchase_date)->format('Y');
    }

    /** Eventi per anno dai movimenti validi; vendita/dismissione come termine. */
    public static function events(Asset $a, ?int $ignoreMovement = null): array
    {
        $ev = [];
        $end = null;
        foreach ($a->movements()->where('status', 'registrato')->get() as $m) {
            if ($m->id === $ignoreMovement) {
                continue;
            }
            $y = (int) $m->movement_date->format('Y');
            $amt = self::m($m->amount);
            $fis = $m->fiscal_amount !== null ? self::m($m->fiscal_amount) : $amt;
            match ($m->type) {
                'incremento' => $ev[$y][] = ['cost_civil' => $amt, 'cost_fiscal' => $fis],
                'decremento' => $ev[$y][] = ['cost_civil' => -$amt, 'cost_fiscal' => -$fis],
                'rivalutazione' => $ev[$y][] = ['cost_civil' => $amt, 'cost_fiscal' => $m->fiscal_recognized ? $fis : 0],
                'svalutazione' => $ev[$y][] = ['fund_civil' => $amt],
                'ripristino' => $ev[$y][] = ['fund_civil' => -$amt],
                'rettifica_ammortamento' => $ev[$y][] = ['fund_civil' => $amt, 'fund_fiscal' => $m->fiscal_amount !== null ? self::m($m->fiscal_amount) : 0],
                'alienazione_parziale' => $ev[$y][] = ['share' => 10000 - self::h((string) $m->share_pct)],
                'alienazione_totale', 'eliminazione' => $end = $m,
                default => null,
            };
        }

        return [$ev, $end];
    }

    public static function rateFor(Asset $a, int $year): array
    {
        $cat = $a->category;
        $limit = $cat->rate ? self::h((string) $cat->rate->coefficient) : self::h((string) $cat->fiscal_rate);
        $r = ['civil' => self::h((string) ($cat->civil_rate ?? $cat->fiscal_rate)), 'fiscal' => self::h((string) $cat->fiscal_rate), 'limit' => $limit, 'manual_civil' => null, 'manual_fiscal' => null];
        $cr = AssetCategoryRate::where('asset_category_id', $cat->id)->where('from_year', '<=', $year)->orderByDesc('from_year')->first();
        if ($cr) {
            $r['civil'] = self::h((string) $cr->civil_rate);
            $r['fiscal'] = self::h((string) $cr->fiscal_rate);
            if (! $cat->rate) {
                $r['limit'] = $r['fiscal'];
            }
        }
        $ar = $a->rates->where('from_year', '<=', $year)->sortByDesc('from_year')->first();
        if ($ar) {
            $r['civil'] = self::h((string) $ar->civil_rate);
            $r['fiscal'] = self::h((string) $ar->fiscal_rate);
            if ((int) $ar->from_year === $year) {
                $r['manual_civil'] = $ar->manual_civil !== null ? self::m((string) $ar->manual_civil) : null;
                $r['manual_fiscal'] = $ar->manual_fiscal !== null ? self::m((string) $ar->manual_fiscal) : null;
            }
        }

        return $r;
    }

    /** Input per il motore. $endOverride simula una vendita (data) prima della sua registrazione. */
    public static function input(Asset $a, ?string $endOverride = null, ?int $ignoreMovement = null): array
    {
        $a->loadMissing(['category.rate', 'rates', 'opening']);
        $s = AssetService::settings($a->company);
        [$events, $endMov] = self::events($a, $ignoreMovement);
        $endDate = $endOverride ?? $endMov?->movement_date?->format('Y-m-d');
        $end = null;
        if ($endDate) {
            $y = (int) substr($endDate, 0, 4);
            $from = max(strtotime("{$y}-01-01"), strtotime(($a->depreciation_start ?? $a->in_service_date ?? $a->purchase_date)->format('Y-m-d')));
            $days = (int) floor((strtotime($endDate) - $from) / 86400) + 1;
            $end = ['year' => $y, 'days' => max(0, $days), 'year_days' => (int) date('z', strtotime("{$y}-12-31")) + 1, 'quota' => (bool) $s['sale_year_quota']];
        }
        $fixed = [];
        foreach ($a->depreciations()->whereIn('status', ['calcolato', 'contabilizzato', 'storico'])->get() as $d) {
            $fixed[$d->year] = ['method' => $d->method, 'civil_rate' => self::h((string) $d->civil_rate), 'fiscal_rate' => self::h((string) $d->fiscal_rate), 'civil_amount' => self::m($d->civil_amount),
                'fiscal_limit' => self::m($d->fiscal_limit), 'fiscal_amount' => self::m($d->fiscal_amount), 'nondeductible' => self::m($d->nondeductible), 'extra_deduction' => self::m($d->extra_deduction),
                'fund_civil_end' => self::m($d->fund_civil_end), 'fund_fiscal_end' => self::m($d->fund_fiscal_end)];
        }
        $o = $a->opening;
        $costC = self::costCivil($a) + ($o ? self::m($o->increments) - self::m($o->decrements) + self::m($o->revaluations) : 0);
        $costF = self::costFiscal($a) + ($o ? self::m($o->increments) - self::m($o->decrements) : 0);

        return [
            'kind' => $a->kind, 'method' => $a->method, 'reduced_pct' => $a->reduced_pct !== null ? self::h((string) $a->reduced_pct) : null, 'start_year' => self::startYear($a),
            'first_year_half' => $a->first_year_half ?? $a->category->first_year_half, 'civil_first_year_half' => (bool) $s['civil_first_year_half'],
            'rates' => fn (int $y) => self::rateFor($a, $y), 'cost_civil' => $costC, 'cost_fiscal' => $costF, 'residual_value' => self::m($a->residual_value),
            'fiscal_cost_cap' => $a->fiscal_cost_cap !== null ? self::m((string) $a->fiscal_cost_cap) : null,
            'fiscal_deductible_pct' => $a->fiscal_deductible_pct !== null ? self::h((string) $a->fiscal_deductible_pct) : null,
            'events' => $events, 'end' => $end, 'fixed' => $fixed,
            'opening' => $o && $o->mode === 'saldi' ? ['last_year' => (int) $o->last_year, 'fund_civil' => self::m($o->fund_civil), 'fund_fiscal' => self::m($o->fund_fiscal)] : null,
            'excluded_years' => $a->excluded ? range((int) date('Y') - 1, (int) date('Y') + 60) : [],
            'low_value' => (bool) $a->low_value, 'extra_pct' => $a->extra_deduction_pct !== null ? self::h((string) $a->extra_deduction_pct) : null,
        ];
    }

    /** Piano completo (passato consolidato, futuro calcolato). */
    public static function plan(Asset $a, ?int $to = null, ?string $endOverride = null): array
    {
        $in = self::input($a, $endOverride);

        return DepreciationEngine::plan($in, $in['start_year'], $to ?? $in['start_year'] + 120);
    }

    /** Riga di un anno (simulazione), oppure null. */
    public static function rowFor(Asset $a, int $year, ?string $endOverride = null): ?array
    {
        foreach (self::plan($a, $year, $endOverride) as $r) {
            if ($r['year'] === $year) {
                return $r;
            }
        }

        return null;
    }

    /** Costi e fondi a una data (civilistici e fiscali), con quota dell'anno proporzionata fino alla data. */
    public static function valuesAt(Asset $a, string $date, ?int $ignoreMovement = null): array
    {
        $in = self::input($a, $date, $ignoreMovement);
        $y = (int) substr($date, 0, 4);
        $rows = DepreciationEngine::plan($in, $in['start_year'], $y);
        $last = end($rows) ?: null;
        $costC = $in['cost_civil'];
        $costF = $in['cost_fiscal'];
        $fundC = $in['opening']['fund_civil'] ?? 0;
        $fundF = $in['opening']['fund_fiscal'] ?? 0;
        foreach ($in['events'] as $ey => $list) {
            if ($ey > $y) {
                continue;
            }
            foreach ($list as $e) {
                if (isset($e['share'])) {
                    $costC = DepreciationEngine::pct($costC, $e['share']);
                    $costF = DepreciationEngine::pct($costF, $e['share']);
                }
                $costC += $e['cost_civil'] ?? 0;
                $costF += $e['cost_fiscal'] ?? 0;
                if (! $last) {
                    $fundC += $e['fund_civil'] ?? 0;
                    $fundF += $e['fund_fiscal'] ?? 0;
                }
            }
        }
        if ($last) {
            $fundC = $last['fund_civil_end'];
            $fundF = $last['fund_fiscal_end'];
        }
        $quota = $last && $last['year'] === $y ? $last : null;

        return ['cost_civil' => $costC, 'cost_fiscal' => ($in['fiscal_cost_cap'] !== null ? min($costF, $in['fiscal_cost_cap']) : $costF), 'fund_civil' => $fundC, 'fund_fiscal' => $fundF,
            'quota_civil' => $quota['civil_amount'] ?? 0, 'quota_fiscal' => $quota['fiscal_amount'] ?? 0, 'year_fixed' => $quota['fixed'] ?? false];
    }

    /**
     * Situazione a fine anno (per registri e prospetti): costi (civilistici/fiscali) dopo i movimenti, fondi inizio/fine, quota dell'anno,
     * movimenti dell'anno per tipo; in caso di vendita/dismissione nell'anno, i valori alla data di uscita.
     */
    public static function snapshot(Asset $a, int $year): array
    {
        $in = self::input($a);
        $rows = collect(DepreciationEngine::plan($in, $in['start_year'], $year))->keyBy('year');
        $o = $in['opening'];
        $costStart = $in['cost_civil'];
        $costEnd = $in['cost_civil'];
        $costFEnd = $in['cost_fiscal'];
        foreach ($in['events'] as $ey => $list) {
            if ($ey > $year) {
                continue;
            }
            foreach ($list as $e) {
                foreach ([&$costEnd, &$costFEnd] as $k => &$ref) {
                    if (isset($e['share'])) {
                        $ref = DepreciationEngine::pct($ref, $e['share']);
                    }
                    $ref += $k === 0 ? ($e['cost_civil'] ?? 0) : ($e['cost_fiscal'] ?? 0);
                }
                unset($ref);
                if ($ey < $year) {
                    if (isset($e['share'])) {
                        $costStart = DepreciationEngine::pct($costStart, $e['share']);
                    }
                    $costStart += $e['cost_civil'] ?? 0;
                }
            }
        }
        $r = $rows->get($year);
        $prev = $rows->filter(fn ($x) => $x['year'] < $year)->last();
        $fundStart = $r['fund_civil_start'] ?? ($prev['fund_civil_end'] ?? ($o['fund_civil'] ?? 0));
        $fundEnd = $r['fund_civil_end'] ?? $fundStart;
        $fundFStart = $r['fund_fiscal_start'] ?? ($prev['fund_fiscal_end'] ?? ($o['fund_fiscal'] ?? 0));
        $fundFEnd = $r['fund_fiscal_end'] ?? $fundFStart;
        $mov = ['acquisizioni' => 0, 'incrementi' => 0, 'decrementi' => 0, 'rivalutazioni' => 0, 'svalutazioni' => 0, 'ripristini' => 0, 'alienazioni' => 0, 'eliminazioni' => 0, 'fondo_stornato' => 0, 'rettifiche' => 0];
        $exit = null;
        foreach ($a->movements()->where('status', 'registrato')->where('year', $year)->get() as $m) {
            $v = self::m($m->amount);
            match ($m->type) {
                'acquisizione' => $mov['acquisizioni'] += $v,
                'incremento' => $mov['incrementi'] += $v,
                'decremento' => $mov['decrementi'] += $v,
                'rivalutazione' => $mov['rivalutazioni'] += $v,
                'svalutazione' => $mov['svalutazioni'] += $v,
                'ripristino' => $mov['ripristini'] += $v,
                'rettifica_ammortamento' => $mov['rettifiche'] += $v,
                default => null,
            };
            if (in_array($m->type, ['alienazione_totale', 'alienazione_parziale', 'eliminazione'], true)) {
                $mov[$m->type === 'eliminazione' ? 'eliminazioni' : 'alienazioni'] += self::m($m->cost_civil);
                $mov['fondo_stornato'] += self::m($m->fund_civil);
                $exit = $m->type !== 'alienazione_parziale' ? $m : $exit;
            }
        }
        if ($exit) { // dopo l'uscita: nessun costo e nessun fondo
            $costEnd = 0;
            $costFEnd = 0;
            $fundEnd = 0;
            $fundFEnd = 0;
        } elseif ($mov['alienazioni']) {
            $fundEnd = $r['fund_civil_end'] ?? $fundEnd;
        }
        $capF = $in['fiscal_cost_cap'];

        return ['cost_start' => $costStart, 'cost_end' => $costEnd, 'cost_fiscal_end' => $capF !== null ? min($costFEnd, $capF) : $costFEnd, 'fund_start' => $fundStart, 'fund_end' => $fundEnd,
            'fund_fiscal_start' => $fundFStart, 'fund_fiscal_end' => $fundFEnd, 'quota_civil' => $r['civil_amount'] ?? 0, 'quota_fiscal' => $r['fiscal_amount'] ?? 0,
            'nondeductible' => $r['nondeductible'] ?? 0, 'extra' => $r['extra_deduction'] ?? 0, 'row' => $r, 'mov' => $mov, 'exit' => $exit,
            'net' => $costEnd - $fundEnd, 'net_fiscal' => ($capF !== null ? min($costFEnd, $capF) : $costFEnd) - $fundFEnd];
    }

    public function refreshStatus(Asset $a): void
    {
        $a->refresh();
        $end = $a->movements()->where('status', 'registrato')->whereIn('type', ['alienazione_totale', 'eliminazione'])->reorder()->orderByDesc('id')->first();
        if ($end) {
            $status = $end->type === 'eliminazione' ? 'eliminato' : 'venduto';
        } elseif ($a->excluded) {
            $status = 'sospeso';
        } else {
            $last = $a->depreciations()->whereIn('status', ['calcolato', 'contabilizzato', 'storico'])->reorder()->orderByDesc('year')->first();
            $status = $last && self::m($last->fund_civil_end) >= self::m($last->base_civil) && self::m($last->base_civil) > 0 ? 'ammortizzato' : 'attivo';
        }
        if ($a->status !== $status) {
            $a->update(['status' => $status]);
        }
    }
}
