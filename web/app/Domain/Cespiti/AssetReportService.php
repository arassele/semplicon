<?php

namespace App\Domain\Cespiti;

use App\Models\Account;
use App\Models\AssetMovement;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\VatRegistration;
use App\Support\Decimal;

/**
 * Stampe e registri A–J come tabelle uniformi (titolo, colonne, righe, totali, avvisi) per anteprima, PDF e CSV.
 * Importi come testo decimale; le colonne con `num` vengono allineate a destra e stampate con il punto delle migliaia.
 */
final class AssetReportService
{
    public const REPORTS = [
        'registro' => 'A · Registro dei beni ammortizzabili', 'elenco' => 'B · Elenco generale cespiti', 'ammortamenti' => 'C · Prospetto ammortamenti dell’esercizio',
        'scheda' => 'D · Scheda singolo cespite', 'fondi' => 'E · Prospetto fondi ammortamento', 'residui' => 'F · Prospetto valori residui',
        'plusminus' => 'G · Prospetto plusvalenze e minusvalenze', 'variazioni' => 'H · Prospetto variazioni immobilizzazioni', 'riconciliazione' => 'I · Riconciliazione contabile',
        'fiscale' => 'J · Prospetto civilistico-fiscale', 'maggiorazioni' => 'K · Prospetto maggiorazioni (super/iperammortamento)',
    ];

    private static function e(int $c): string
    {
        return Decimal::fromMinor($c);
    }

    private function assets(Company $c, array $f = [])
    {
        return $c->assets()->with(['category.rate', 'rates', 'opening', 'company'])
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('asset_category_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['location'] ?? null, fn ($q, $v) => $q->where('location', 'like', "%{$v}%"))
            ->when($f['cost_center'] ?? null, fn ($q, $v) => $q->where('cost_center', 'like', "%{$v}%"))
            ->when($f['purchase_year'] ?? null, fn ($q, $v) => $q->whereYear('purchase_date', (int) $v))
            ->when($f['account'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('account_asset_id', $v)->orWhereHas('category', fn ($k) => $k->where('account_asset_id', $v))))
            ->orderBy('number')->get();
    }

    public function build(Company $c, string $key, int $year, array $f = []): array
    {
        $method = 'r'.ucfirst($key);
        abort_unless(isset(self::REPORTS[$key]) && method_exists($this, $method), 404);
        $r = $this->{$method}($c, $year, $f);

        return ['key' => $key, 'title' => self::REPORTS[$key], 'year' => $year, 'company' => ['name' => $c->name, 'partita_iva' => $c->partita_iva], 'notes' => [], 'totals' => [], ...$r];
    }

    private function rRegistro(Company $c, int $year, array $f): array
    {
        $rows = [];
        $t = array_fill_keys(['cost', 'riv', 'sval', 'inc', 'dec', 'fp', 'q', 'qf', 'fe', 'res'], 0);
        foreach ($this->assets($c, $f) as $a) {
            if ($a->purchase_date->format('Y') > $year) {
                continue;
            }
            $s = AssetLedger::snapshot($a, $year);
            $before = AssetMovement::where('asset_id', $a->id)->where('status', 'registrato')->where('year', '<', $year)->whereIn('type', ['alienazione_totale', 'eliminazione'])->exists();
            if ($before) {
                continue;
            }
            $m = $s['mov'];
            $cum = fn (string $type) => (int) $a->movements()->where('status', 'registrato')->where('type', $type)->where('year', '<=', $year)->get()->sum(fn ($x) => AssetLedger::m($x->amount));
            $exit = $s['exit'];
            $fundEndShown = $exit ? AssetLedger::m($exit->fund_civil) : $s['fund_end'];
            $res = $exit ? 0 : $s['net'];
            $rows[] = [$a->code, $a->description, $a->category->name, $a->purchase_date->format('Y'), self::e(AssetLedger::costCivil($a)), self::e($cum('rivalutazione')), self::e($cum('svalutazione')),
                self::e($cum('incremento')), self::e($cum('decremento')), self::e($s['fund_start']), number_format((float) ($s['row']['civil_rate'] ?? 0) / 100, 2, ',', ''), self::e($s['quota_civil']), self::e($s['quota_fiscal']),
                self::e($fundEndShown), self::e($res), $exit ? (AssetMovementService::TYPES[$exit->type].' '.$exit->movement_date->format('d/m/Y').($exit->price !== null ? ' · '.str_replace('.', ',', (string) $exit->price) : '')) : ''];
            $t['cost'] += AssetLedger::costCivil($a);
            $t['q'] += $s['quota_civil'];
            $t['qf'] += $s['quota_fiscal'];
            $t['fp'] += $s['fund_start'];
            $t['fe'] += $fundEndShown;
            $t['res'] += $res;
        }

        return ['columns' => [['Codice'], ['Descrizione'], ['Categoria'], ['Anno acq.'], ['Costo originario', 'num'], ['Rivalutazioni', 'num'], ['Svalutazioni', 'num'], ['Incrementi', 'num'], ['Decrementi', 'num'],
            ['Fondo precedente', 'num'], ['Aliquota %', 'num'], ['Quota esercizio', 'num'], ['Quota fiscale', 'num'], ['Fondo finale', 'num'], ['Residuo', 'num'], ['Alienazione / eliminazione']],
            'rows' => $rows, 'totals' => ['Costo originario' => self::e($t['cost']), 'Fondo precedente' => self::e($t['fp']), 'Quote dell’esercizio' => self::e($t['q']), 'Quote fiscali' => self::e($t['qf']),
                'Fondo finale' => self::e($t['fe']), 'Residuo' => self::e($t['res'])],
            'notes' => ['Registro dei beni ammortizzabili (art. 16 DPR 600/1973): per le imprese in contabilità semplificata e i professionisti le annotazioni possono essere tenute nei registri IVA o nel libro giornale (art. 13 DPR 435/2001): verifica la modalità adottata.']];
    }

    private function rElenco(Company $c, int $year, array $f): array
    {
        $rows = [];
        foreach ($this->assets($c, $f) as $a) {
            $s = AssetLedger::snapshot($a, $year);
            $rows[] = [$a->code, $a->description, $a->category->name, $a->purchase_date->format('d/m/Y'), self::e(AssetLedger::costCivil($a)), self::e($s['exit'] ? 0 : $s['fund_end']),
                self::e($s['exit'] ? 0 : $s['net']), str_replace('.', ',', (string) ($a->category->civil_rate ?? $a->category->fiscal_rate)), AssetService::STATUS[$a->status] ?? $a->status, $a->location, $a->cost_center];
        }

        return ['columns' => [['Codice'], ['Descrizione'], ['Categoria'], ['Acquisto'], ['Valore storico', 'num'], ['Fondo', 'num'], ['Netto', 'num'], ['Aliquota %', 'num'], ['Stato'], ['Ubicazione'], ['Centro di costo']], 'rows' => $rows];
    }

    private function rAmmortamenti(Company $c, int $year, array $f): array
    {
        $rows = [];
        $byCat = [];
        $byAcc = [];
        foreach ($this->assets($c, $f) as $a) {
            $s = AssetLedger::snapshot($a, $year);
            if (! $s['row']) {
                continue;
            }
            $r = $s['row'];
            $rows[] = [$a->code, $a->description, $a->category->name, AssetService::METHODS[$r['method']] ?? $r['method'], self::e($r['base_civil']), number_format($r['civil_rate'] / 100, 2, ',', ''),
                self::e($r['civil_amount']), self::e($r['fiscal_limit']), self::e($r['fiscal_amount']), self::e($r['nondeductible']), $r['fixed'] ? 'calcolato' : 'simulato', implode(' ', $r['warnings'])];
            $byCat[$a->category->name] = ($byCat[$a->category->name] ?? 0) + $r['civil_amount'];
            $acc = Account::find($a->account('expense'));
            $k = $acc ? "{$acc->code} {$acc->name}" : 'Conto non indicato';
            $byAcc[$k] = ($byAcc[$k] ?? 0) + $r['civil_amount'];
        }
        $tot = [];
        foreach ($byCat as $k => $v) {
            $tot["Categoria · {$k}"] = self::e($v);
        }
        foreach ($byAcc as $k => $v) {
            $tot["Conto · {$k}"] = self::e($v);
        }

        return ['columns' => [['Codice'], ['Descrizione'], ['Categoria'], ['Metodo'], ['Base', 'num'], ['Aliquota %', 'num'], ['Quota civilistica', 'num'], ['Limite fiscale', 'num'], ['Quota deducibile', 'num'],
            ['Non deducibile', 'num'], ['Stato'], ['Avvisi']], 'rows' => $rows, 'totals' => $tot];
    }

    private function rScheda(Company $c, int $year, array $f): array
    {
        $a = $c->assets()->findOrFail($f['asset'] ?? 0);
        $rows = [];
        foreach (AssetLedger::plan($a) as $r) {
            $rows[] = ['Piano', (string) $r['year'], self::e($r['base_civil']), number_format($r['civil_rate'] / 100, 2, ',', ''), self::e($r['civil_amount']), self::e($r['fiscal_amount']),
                self::e($r['fund_civil_start']), self::e($r['fund_civil_end']), self::e($r['base_civil'] - $r['fund_civil_end']), self::e($r['base_fiscal'] - $r['fund_fiscal_end']), $r['fixed'] ? 'calcolato' : 'previsto'];
        }
        foreach ($a->movements()->get() as $m) {
            $rows[] = ['Movimento', $m->movement_date->format('d/m/Y'), AssetMovementService::TYPES[$m->type] ?? $m->type, '', self::e(AssetLedger::m($m->amount)), '', '', '', '', '', $m->status.' · '.$m->causale];
        }

        return ['title' => self::REPORTS['scheda'].' · '.$a->code.' '.$a->description, 'columns' => [['Tipo'], ['Esercizio / data'], ['Valore iniziale', 'num'], ['Aliquota %', 'num'], ['Quota civilistica', 'num'],
            ['Quota fiscale', 'num'], ['Fondo iniziale', 'num'], ['Fondo finale', 'num'], ['Valore netto', 'num'], ['Residuo fiscale', 'num'], ['Stato / causale']], 'rows' => $rows,
            'notes' => ['Categoria '.$a->category->name.' · acquisto '.$a->purchase_date->format('d/m/Y').' · entrata in funzione '.($a->in_service_date?->format('d/m/Y') ?? '—').' · costo '.str_replace('.', ',', (string) $a->original_cost).' · stato '.(AssetService::STATUS[$a->status] ?? $a->status)]];
    }

    private function rFondi(Company $c, int $year, array $f): array
    {
        $g = [];
        foreach ($this->assets($c, $f) as $a) {
            $s = AssetLedger::snapshot($a, $year);
            $acc = Account::find($a->account('fund'));
            $k = $acc ? "{$acc->code} {$acc->name}" : 'Fondo non indicato';
            $g[$k] ??= [0, 0, 0, 0, 0, 0];
            $g[$k][0] += $s['fund_start'];
            $g[$k][1] += $s['quota_civil'];
            $g[$k][2] += $s['mov']['svalutazioni'] - $s['mov']['ripristini'];
            $g[$k][3] += $s['mov']['rettifiche'];
            $g[$k][4] += $s['mov']['fondo_stornato'];
            $g[$k][5] += $s['fund_end'];
        }

        return ['columns' => [['Fondo'], ['Saldo iniziale', 'num'], ['Ammortamenti', 'num'], ['Svalutazioni − ripristini', 'num'], ['Rettifiche', 'num'], ['Storni per alienazioni/eliminazioni', 'num'], ['Saldo finale', 'num']],
            'rows' => array_map(fn ($k, $v) => [$k, ...array_map(fn ($x) => self::e($x), $v)], array_keys($g), $g)];
    }

    private function rResidui(Company $c, int $year, array $f): array
    {
        $rows = [];
        $t = [0, 0, 0, 0];
        foreach ($this->assets($c, $f) as $a) {
            $s = AssetLedger::snapshot($a, $year);
            if ($s['exit'] || $a->purchase_date->format('Y') > $year) {
                continue;
            }
            $rows[] = [$a->code, $a->description, self::e($s['cost_end']), self::e($s['fund_end']), self::e($s['net']), self::e($s['net_fiscal'])];
            $t[0] += $s['cost_end'];
            $t[1] += $s['fund_end'];
            $t[2] += $s['net'];
            $t[3] += $s['net_fiscal'];
        }

        return ['columns' => [['Codice'], ['Descrizione'], ['Costo', 'num'], ['Fondo', 'num'], ['Valore netto contabile', 'num'], ['Residuo fiscale', 'num']], 'rows' => $rows,
            'totals' => ['Costo' => self::e($t[0]), 'Fondo' => self::e($t[1]), 'Valore netto' => self::e($t[2]), 'Residuo fiscale' => self::e($t[3])]];
    }

    private function rPlusminus(Company $c, int $year, array $f): array
    {
        $rows = [];
        $t = [0, 0];
        foreach (AssetMovement::where('company_id', $c->id)->where('year', $year)->where('status', 'registrato')->whereIn('type', ['alienazione_totale', 'alienazione_parziale', 'eliminazione'])->with('asset')->orderBy('movement_date')->get() as $m) {
            $gc = AssetLedger::m($m->gain_civil);
            $gf = AssetLedger::m($m->gain_fiscal);
            $rows[] = [$m->movement_date->format('d/m/Y'), $m->asset->code.' '.$m->asset->description, AssetMovementService::TYPES[$m->type].($m->share_pct ? ' '.str_replace('.', ',', (string) $m->share_pct).'%' : ''),
                (string) $m->cost_civil, (string) $m->fund_civil, (string) $m->book_value, (string) ($m->price ?? '0.00'), self::e($gc), (string) $m->fiscal_value, self::e($gf), self::e($gf - $gc)];
            $t[0] += $gc;
            $t[1] += $gf;
        }

        return ['columns' => [['Data'], ['Bene'], ['Operazione'], ['Costo', 'num'], ['Fondo', 'num'], ['Residuo', 'num'], ['Prezzo netto', 'num'], ['Risultato civilistico', 'num'], ['Valore fiscale', 'num'],
            ['Risultato fiscale', 'num'], ['Differenza fiscale', 'num']], 'rows' => $rows, 'totals' => ['Risultato civilistico' => self::e($t[0]), 'Risultato fiscale' => self::e($t[1])],
            'notes' => ['Le regole di tassazione (es. rateizzazione delle plusvalenze, art. 86 c. 4 TUIR) non sono applicate automaticamente: verifica con il professionista.']];
    }

    private function rVariazioni(Company $c, int $year, array $f): array
    {
        $g = [];
        foreach ($this->assets($c, $f) as $a) {
            if ($a->purchase_date->format('Y') > $year) {
                continue;
            }
            $s = AssetLedger::snapshot($a, $year);
            $m = $s['mov'];
            $k = $a->category->name;
            $g[$k] ??= array_fill(0, 12, 0);
            $isNew = (int) $a->purchase_date->format('Y') === $year;
            $g[$k][0] += $isNew ? 0 : $s['cost_start'];
            $g[$k][1] += $m['acquisizioni'];
            $g[$k][2] += $m['incrementi'];
            $g[$k][3] += $m['rivalutazioni'];
            $g[$k][4] += $m['decrementi'];
            $g[$k][5] += $m['alienazioni'];
            $g[$k][6] += $m['eliminazioni'];
            $g[$k][7] += $s['cost_end'];
            $g[$k][8] += $isNew ? 0 : $s['fund_start'];
            $g[$k][9] += $s['quota_civil'] + $m['svalutazioni'] - $m['ripristini'] + $m['rettifiche'];
            $g[$k][10] += $m['fondo_stornato'];
            $g[$k][11] += $s['fund_end'];
        }
        $rows = [];
        $notes = [];
        foreach ($g as $k => $v) {
            $check = $v[0] + $v[1] + $v[2] + $v[3] - $v[4] - $v[5] - $v[6];
            if ($check !== $v[7]) {
                $notes[] = "Categoria {$k}: il costo non quadra (calcolato ".self::e($check).', saldo finale '.self::e($v[7]).').';
            }
            $rows[] = [$k, ...array_map(fn ($x) => self::e($x), $v)];
        }

        return ['columns' => [['Categoria'], ['Costo iniziale', 'num'], ['Acquisizioni', 'num'], ['Incrementi', 'num'], ['Rivalutazioni', 'num'], ['Decrementi', 'num'], ['Alienazioni', 'num'], ['Eliminazioni', 'num'],
            ['Costo finale', 'num'], ['Fondo iniziale', 'num'], ['Ammortamenti e rettifiche', 'num'], ['Fondo stornato', 'num'], ['Fondo finale', 'num']], 'rows' => $rows,
            'notes' => [...$notes, 'Costo: saldo iniziale + acquisizioni + incrementi + rivalutazioni − decrementi − alienazioni − eliminazioni = saldo finale. Fondi a parte.']];
    }

    /** Conto per conto: valori secondo Cespiti rispetto al saldo secondo registrazioni IVA, Prima nota cespiti e saldi di apertura. */
    public function reconciliation(Company $c, int $year): array
    {
        $out = [];
        $add = function (?int $acc, string $kind, int $cespiti) use (&$out) {
            if (! $acc) {
                return;
            }
            $out[$acc][$kind] = ($out[$acc][$kind] ?? 0) + $cespiti;
        };
        foreach ($c->assets()->with(['category.rate', 'rates', 'opening', 'company'])->get() as $a) {
            if ($a->purchase_date->format('Y') > $year) {
                continue;
            }
            $s = AssetLedger::snapshot($a, $year);
            $add($a->account('asset'), 'cost', $s['cost_end']);
            $add($a->account('fund'), 'fund', $s['fund_end']);
            $add($a->account('expense'), 'expense', $s['quota_civil']);
            if ($a->opening) { // Saldi iniziali da sistemi precedenti: già nella contabilità, nessuna nuova registrazione
                $add($a->account('asset'), 'open_cost', AssetLedger::costCivil($a) + AssetLedger::m($a->opening->increments) - AssetLedger::m($a->opening->decrements) + AssetLedger::m($a->opening->revaluations));
                $add($a->account('fund'), 'open_fund', AssetLedger::m($a->opening->fund_civil));
            }
        }
        $rows = [];
        foreach ($out as $accId => $v) {
            $acc = Account::find($accId);
            $journal = JournalLine::where('account_id', $accId)->whereHas('entry', fn ($q) => $q->where('company_id', $c->id)->where('year', '<=', $year))->get();
            $j = (int) $journal->sum(fn ($l) => AssetLedger::m($l->dare) - AssetLedger::m($l->avere));
            $reg = 0;
            foreach (VatRegistration::where('company_id', $c->id)->where('year', '<=', $year)->with('lines')->get() as $r) {
                foreach ($r->lines as $l) {
                    if ($l->account_id === $accId) {
                        $reg += $r->sign * (AssetLedger::m($l->taxable) + AssetLedger::m($l->tax) - AssetLedger::m($l->deductible_tax)) * ($r->direction->value === 'vendita' ? -1 : 1);
                    }
                }
            }
            if (isset($v['cost'])) {
                $book = $reg + $j + ($v['open_cost'] ?? 0);
                $rows[] = [$acc->code.' '.$acc->name, 'Immobilizzazione', self::e($v['cost']), self::e($reg), self::e($j), self::e($v['open_cost'] ?? 0), self::e($book), self::e($v['cost'] - $book)];
            }
            if (isset($v['fund'])) {
                $book = -$j + ($v['open_fund'] ?? 0);
                $rows[] = [$acc->code.' '.$acc->name, 'Fondo ammortamento', self::e($v['fund']), '0.00', self::e(-$j), self::e($v['open_fund'] ?? 0), self::e($book), self::e($v['fund'] - $book)];
            }
            if (isset($v['expense'])) {
                $je = (int) JournalLine::where('account_id', $accId)->whereHas('entry', fn ($q) => $q->where('company_id', $c->id)->where('year', $year))->get()->sum(fn ($l) => AssetLedger::m($l->dare) - AssetLedger::m($l->avere));
                $rows[] = [$acc->code.' '.$acc->name, 'Ammortamenti '.$year, self::e($v['expense']), '0.00', self::e($je), '0.00', self::e($je), self::e($v['expense'] - $je)];
            }
        }

        return $rows;
    }

    private function rRiconciliazione(Company $c, int $year, array $f): array
    {
        $rows = $this->reconciliation($c, $year);
        $diff = array_filter($rows, fn ($r) => $r[7] !== '0.00');

        return ['columns' => [['Conto'], ['Tipo'], ['Valore cespiti', 'num'], ['Registrazioni IVA', 'num'], ['Prima nota cespiti', 'num'], ['Saldi iniziali importati', 'num'], ['Saldo contabile', 'num'], ['Differenza', 'num']],
            'rows' => $rows, 'notes' => [$diff ? count($diff).' conti con differenze: controlla registrazioni, calcoli non contabilizzati o conti dei cespiti.' : 'Nessuna differenza.',
                'Le quote calcolate ma non ancora contabilizzate risultano come differenza sui fondi e sugli ammortamenti.']];
    }

    private function rFiscale(Company $c, int $year, array $f): array
    {
        $rows = [];
        $t = [0, 0, 0, 0, 0];
        foreach ($this->assets($c, $f) as $a) {
            $s = AssetLedger::snapshot($a, $year);
            if (! $s['row']) {
                continue;
            }
            $r = $s['row'];
            $diff = $r['civil_amount'] - $r['fiscal_amount'];
            $kind = $diff === 0 ? '' : (($a->fiscal_deductible_pct !== null || $r['civil_rate'] > $r['fiscal_rate'] && ! ($r['year'] === AssetLedger::startYear($a))) ? 'permanente (da verificare)' : 'temporanea');
            $rows[] = [$a->code, $a->description, self::e($r['civil_amount']), self::e($r['fiscal_amount']), self::e($diff), $kind, self::e($r['extra_deduction']), self::e($s['net']), self::e($s['net_fiscal'])];
            $t[0] += $r['civil_amount'];
            $t[1] += $r['fiscal_amount'];
            $t[2] += $diff;
            $t[3] += $r['extra_deduction'];
        }

        return ['columns' => [['Codice'], ['Descrizione'], ['Quota civilistica', 'num'], ['Quota deducibile', 'num'], ['Differenza', 'num'], ['Natura differenza'], ['Maggiorazione', 'num'],
            ['Valore netto contabile', 'num'], ['Residuo fiscale', 'num']], 'rows' => $rows,
            'totals' => ['Quote civilistiche' => self::e($t[0]), 'Quote deducibili' => self::e($t[1]), 'Variazioni in aumento da esaminare' => self::e($t[2]), 'Maggiorazioni (variazioni in diminuzione)' => self::e($t[3])],
            'notes' => ['Le differenze non generano scritture contabili: sono possibili variazioni fiscali da esaminare in dichiarazione.']];
    }

    /** K: Maggiorazioni per cespite (decisione n. 81): quota deducibile × % = variazione in diminuzione, cumulata negli anni. */
    private function rMaggiorazioni(Company $c, int $year, array $f): array
    {
        $rows = [];
        $t = [0, 0, 0];
        foreach ($this->assets($c, $f) as $a) {
            if ($a->extra_deduction_pct === null) {
                continue;
            }
            $plan = collect(AssetLedger::plan($a, $year))->filter(fn ($r) => $r['year'] <= $year);
            $cur = $plan->firstWhere('year', $year);
            $prev = $plan->filter(fn ($r) => $r['year'] < $year)->sum('extra_deduction');
            $q = $cur['fiscal_amount'] ?? 0;
            $x = $cur['extra_deduction'] ?? 0;
            $rows[] = [$a->code, $a->description, (string) $a->extra_deduction_note, str_replace('.', ',', (string) $a->extra_deduction_pct), self::e($q), self::e($x), self::e($prev), self::e($prev + $x)];
            $t[0] += $q;
            $t[1] += $x;
            $t[2] += $prev + $x;
        }

        return ['columns' => [['Codice'], ['Descrizione'], ['Riferimento normativo'], ['Maggiorazione %'], ['Quota deducibile', 'num'], ['Maggiorazione esercizio', 'num'], ['Esercizi precedenti', 'num'], ['Totale fruito', 'num']],
            'rows' => $rows, 'totals' => ['Quote deducibili' => self::e($t[0]), 'Maggiorazioni dell’esercizio (variazione in diminuzione)' => self::e($t[1]), 'Maggiorazioni cumulate' => self::e($t[2])],
            'notes' => ['Maggiorazione = quota fiscale deducibile × percentuale indicata nel cespite. Variazione in diminuzione extracontabile: nessuna scrittura in prima nota. Percentuali e spettanza secondo la norma indicata, da verificare con il professionista.']];
    }

    public function csv(array $r): string
    {
        $cell = fn ($v) => preg_match('/[;"\n]/', (string) $v) ? '"'.str_replace('"', '""', (string) $v).'"' : (string) $v;
        $num = array_map(fn ($c) => ($c[1] ?? '') === 'num', $r['columns']);
        $lines = ["\xEF\xBB\xBF".implode(';', array_map(fn ($c) => $cell($c[0]), $r['columns']))];
        foreach ($r['rows'] as $row) {
            $lines[] = implode(';', array_map(fn ($v, $k) => $cell($num[$k] ?? false ? str_replace('.', ',', (string) $v) : $v), $row, array_keys($row)));
        }
        foreach ($r['totals'] as $k => $v) {
            $lines[] = $cell($k).';'.str_replace('.', ',', $v);
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
