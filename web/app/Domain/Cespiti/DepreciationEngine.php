<?php

namespace App\Domain\Cespiti;

/**
 * Motore di ammortamento (puro, senza database): importi in centesimi, aliquote in centesimi di punto percentuale (20 % = 2000).
 *
 * Input per cespite (`plan()`):
 *  - kind: materiale | immateriale | non_ammortizzabile
 *  - method: ordinario | ridotto | pieno | manuale; reduced_pct (centesimi: quota della quota ordinaria con «ridotto»)
 *  - start_year: primo anno di ammortamento (entrata in funzione ovvero decorrenza)
 *  - first_year_half: metà dell'aliquota massima fiscale nel primo anno (solo materiale, art. 102 c. 2 TUIR; mai automatico per gli immateriali)
 *  - civil_first_year_half: dimezzare anche la quota civilistica nel primo anno (impostazione dell'azienda)
 *  - rates(year): ['civil' => int, 'fiscal' => int (applicata), 'limit' => int (aliquota massima ordinaria), 'manual_civil' => ?int, 'manual_fiscal' => ?int]
 *  - cost_civil / cost_fiscal (valori iniziali, senza movimenti), residual_value (solo civilistico), fiscal_cost_cap, fiscal_deductible_pct (centesimi)
 *  - events[year]: elenco ['cost_civil' => Δ, 'cost_fiscal' => Δ, 'fund_civil' => Δ, 'fund_fiscal' => Δ, 'share' => quota residua dopo vendita parziale (centesimi)]
 *  - end: ['year' => int, 'days' => int, 'year_days' => int, 'quota' => bool] (vendita/dismissione nell'anno, quota proporzionata)
 *  - fixed[year]: anni già confermati (quota e fondi sono definitivi, non vengono ricalcolati)
 *  - opening: ['last_year' => int, 'fund_civil' => int, 'fund_fiscal' => int]
 *  - excluded_years: anni senza quota (sospeso); low_value: deduzione dell'intero valore fiscale nel primo anno (art. 102 c. 5 TUIR)
 *  - extra_pct: maggiorazione (super/iperammortamento) in centesimi: quota aggiuntiva extracontabile = quota deducibile × %
 *  - reduce[year]: quota ridotta dal calcolo dell'anno (centesimi della quota ordinaria; solo materiali, non con «manuale»), decisione n. 81
 */
final class DepreciationEngine
{
    public static function round(int|float $num, int $den = 1): int
    {
        // mezzi centesimi arrotondati per eccesso in valore assoluto; aritmetica intera dove possibile
        if (is_int($num) && $den > 0) {
            $q = intdiv(abs($num) * 2 + $den, 2 * $den);

            return $num < 0 ? -$q : $q;
        }

        return (int) round($num / $den, 0, PHP_ROUND_HALF_UP);
    }

    /** Quota: amount × pct / 10000 (pct in centesimi di punto percentuale). */
    public static function pct(int $amount, int $pct): int
    {
        return self::round($amount * $pct, 10000);
    }

    /**
     * @return list<array{year: int, method: string, civil_rate: int, fiscal_rate: int, base_civil: int, base_fiscal: int, fund_civil_start: int, civil_amount: int, fund_civil_end: int,
     *     fund_fiscal_start: int, fiscal_limit: int, fiscal_amount: int, fund_fiscal_end: int, nondeductible: int, extra_deduction: int, days: ?int, fixed: bool, warnings: list<string>}>
     */
    public static function plan(array $a, int $fromYear, int $toYear): array
    {
        $out = [];
        if (($a['kind'] ?? 'materiale') === 'non_ammortizzabile') {
            return $out;
        }
        $costC = $a['cost_civil'];
        $costF = $a['cost_fiscal'];
        $fundC = 0;
        $fundF = 0;
        $start = $a['start_year'];
        $openYear = $a['opening']['last_year'] ?? null;
        if ($openYear !== null) {
            $fundC = $a['opening']['fund_civil'];
            $fundF = $a['opening']['fund_fiscal'];
        }
        $first = min($fromYear, $start);
        for ($y = $first; $y <= $toYear; $y++) {
            foreach ($a['events'][$y] ?? [] as $e) {
                if (isset($e['share'])) { // vendita parziale: costi e fondi sulla quota residua
                    $costC = self::pct($costC, $e['share']);
                    $costF = self::pct($costF, $e['share']);
                    $fundC = self::pct($fundC, $e['share']);
                    $fundF = self::pct($fundF, $e['share']);
                }
                $costC += $e['cost_civil'] ?? 0;
                $costF += $e['cost_fiscal'] ?? 0;
                $fundC += $e['fund_civil'] ?? 0;
                $fundF += $e['fund_fiscal'] ?? 0;
            }
            if ($y < $start || ($openYear !== null && $y <= $openYear)) {
                continue;
            }
            $end = $a['end'] ?? null;
            if ($end && $y > $end['year']) {
                break;
            }
            $baseC = max(0, $costC - ($a['residual_value'] ?? 0));
            $baseF = ($a['fiscal_cost_cap'] ?? null) !== null ? min($costF, $a['fiscal_cost_cap']) : $costF;
            $r = ($a['rates'])($y);
            $row = ['year' => $y, 'method' => $a['method'], 'civil_rate' => $r['civil'], 'fiscal_rate' => min($r['fiscal'], $r['limit']), 'base_civil' => $baseC, 'base_fiscal' => $baseF,
                'fund_civil_start' => $fundC, 'fund_fiscal_start' => $fundF, 'days' => null, 'fixed' => false, 'warnings' => [], 'extra_deduction' => 0];
            if (isset($a['fixed'][$y])) {
                $f = $a['fixed'][$y];
                $fundC = $f['fund_civil_end'];
                $fundF = $f['fund_fiscal_end'];
                $out[] = [...$row, 'method' => $f['method'], 'civil_rate' => $f['civil_rate'], 'fiscal_rate' => $f['fiscal_rate'], 'civil_amount' => $f['civil_amount'], 'fiscal_limit' => $f['fiscal_limit'],
                    'fiscal_amount' => $f['fiscal_amount'], 'nondeductible' => $f['nondeductible'], 'extra_deduction' => $f['extra_deduction'] ?? 0, 'fund_civil_end' => $fundC, 'fund_fiscal_end' => $fundF, 'fixed' => true];

                continue;
            }
            if ($fundC >= $baseC && $fundF >= $baseF && ($baseC > 0 || $baseF > 0)) {
                break; // completamente ammortizzato
            }
            $firstYear = $y === $start;
            $fraction = [1, 1];
            if ($end && $y === $end['year']) {
                if (! $end['quota']) {
                    break;
                }
                $fraction = [$end['days'], $end['year_days']];
                $row['days'] = $end['days'];
            }
            if ($r['fiscal'] > $r['limit']) {
                $row['warnings'][] = 'Aliquota fiscale applicata superiore al coefficiente ordinario: l’eccedenza non è considerata deducibile.';
            }
            if (in_array($y, $a['excluded_years'] ?? [], true)) {
                $out[] = [...$row, 'civil_amount' => 0, 'fiscal_limit' => 0, 'fiscal_amount' => 0, 'nondeductible' => 0, 'fund_civil_end' => $fundC, 'fund_fiscal_end' => $fundF, 'warnings' => ['Cespite escluso dal calcolo (sospeso).']];

                continue;
            }
            $halfF = $firstYear && ($a['kind'] === 'materiale') && ($a['first_year_half'] ?? true);
            $halfC = $firstYear && ($a['civil_first_year_half'] ?? false) && $a['kind'] === 'materiale' && $a['method'] === 'ordinario';
            // Quota civilistica
            $ordinary = self::pct($baseC, $r['civil']);
            $civil = match ($a['method']) {
                'ridotto' => self::pct($ordinary, $a['reduced_pct'] ?? 10000),
                'manuale' => $r['manual_civil'] ?? $ordinary,
                default => $ordinary,
            };
            $reduce = $a['reduce'][$y] ?? null;
            if ($reduce !== null && $reduce < 10000 && $a['kind'] === 'materiale' && $a['method'] !== 'manuale') {
                $civil = self::pct($civil, $reduce);
                $row['method'] = 'ridotto';
                $row['reduced_pct'] = $reduce;
            }
            if ($halfC) {
                $civil = self::round($civil, 2);
            }
            $civil = self::round($civil * $fraction[0], $fraction[1]);
            $remainingC = max(0, $baseC - $fundC);
            if ($civil > $remainingC) {
                if ($a['method'] === 'manuale') {
                    $row['warnings'][] = 'Quota manuale ridotta al valore residuo da ammortizzare.';
                }
                $civil = $remainingC;
            }
            // Limite fiscale (aliquota massima ordinaria, dimezzata nel primo anno per i materiali)
            $limitRate = min($r['fiscal'], $r['limit']);
            $limit = self::pct($baseF, $limitRate);
            if ($halfF) {
                $limit = self::round($limit, 2);
            }
            $limit = self::round($limit * $fraction[0], $fraction[1]);
            $remainingF = max(0, $baseF - $fundF);
            if (($a['low_value'] ?? false) && $firstYear) {
                $limit = $remainingF; // art. 102 c. 5: intero importo deducibile nell'anno
                $quotaF = $remainingF;
            } else {
                $limit = min($limit, $remainingF);
                $quotaF = $a['method'] === 'manuale' && $r['manual_fiscal'] !== null ? min($r['manual_fiscal'], $limit) : min($civil, $limit);
                if ($remainingC === 0 && $civil === 0 && $remainingF > 0) {
                    // Piano civilistico terminato, residuo fiscale (es. dal primo anno dimezzato): quota entro l'aliquota massima
                    $quotaF = $limit;
                    $row['warnings'][] = 'Quota fiscale residua senza quota civilistica: ammessa nel registro dei beni ammortizzabili (semplificata, professionisti); in contabilità ordinaria da verificare.';
                }
            }
            $deductible = isset($a['fiscal_deductible_pct']) && $a['fiscal_deductible_pct'] !== null ? self::pct($quotaF, $a['fiscal_deductible_pct']) : $quotaF;
            if ($a['method'] === 'pieno' && $halfF && $civil > $limit) {
                $row['warnings'][] = 'Quota piena nel primo esercizio: l’eccedenza sul limite fiscale dimezzato non è deducibile.';
            }
            $extra = ($a['extra_pct'] ?? null) ? self::pct($deductible, $a['extra_pct']) : 0;
            $fundC += $civil;
            $fundF += $quotaF;
            $out[] = [...$row, 'fiscal_rate' => $limitRate, 'civil_amount' => $civil, 'fiscal_limit' => $limit, 'fiscal_amount' => $deductible, 'nondeductible' => max(0, $civil - $deductible),
                'extra_deduction' => $extra, 'fund_civil_end' => $fundC, 'fund_fiscal_end' => $fundF];
            if ($fundC >= $baseC && $fundF >= $baseF && $y >= $fromYear && ! ($a['events'][$y + 1] ?? null)) {
                break;
            }
        }

        return array_values(array_filter($out, fn ($r) => $r['year'] >= $fromYear || $r['fixed']));
    }
}
