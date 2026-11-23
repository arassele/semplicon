<?php

namespace App\Domain\Cespiti;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AssetRate;
use App\Models\Company;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Movimenti con storico immutabile; correzione solo tramite storno. I movimenti che incidono sul valore sono ammessi solo in anni senza quota consolidata.
 * Registrazioni in Prima nota cespiti solo dopo conferma esplicita (`confirm`).
 */
final class AssetMovementService
{
    public const TYPES = [
        'acquisizione' => 'Acquisizione', 'incremento' => 'Incremento di valore', 'decremento' => 'Decremento di valore', 'rivalutazione' => 'Rivalutazione', 'svalutazione' => 'Svalutazione',
        'ripristino' => 'Ripristino di valore', 'alienazione_totale' => 'Alienazione totale', 'alienazione_parziale' => 'Alienazione parziale', 'eliminazione' => 'Eliminazione',
        'ammortamento' => 'Ammortamento annuale', 'rettifica_ammortamento' => 'Rettifica ammortamento', 'riclassificazione' => 'Riclassificazione categoria', 'trasferimento' => 'Trasferimento centro di costo',
    ];

    private const MANUAL = ['incremento', 'decremento', 'rivalutazione', 'svalutazione', 'ripristino', 'alienazione_totale', 'alienazione_parziale', 'eliminazione', 'rettifica_ammortamento', 'riclassificazione', 'trasferimento'];

    public function __construct(private JournalService $journal) {}

    private static function amount(?string $v, string $field, array &$e, bool $required = true, bool $signed = false): ?int
    {
        $v = trim(str_replace(',', '.', (string) $v));
        if ($v === '') {
            if ($required) {
                $e[$field] = 'Indica l’importo.';
            }

            return null;
        }
        if (! preg_match($signed ? '/^-?\d{1,12}(\.\d{1,2})?$/' : '/^\d{1,12}(\.\d{1,2})?$/', $v)) {
            $e[$field] = 'Importo non valido (es. 1250,00).';

            return null;
        }

        return Decimal::toMinor($v);
    }

    /** Anteprima (senza salvare): valori, risultato e scrittura contabile. */
    public function preview(Company $c, Asset $a, array $d): array
    {
        return $this->build($c, $a, $d);
    }

    private function build(Company $c, Asset $a, array $d): array
    {
        $e = [];
        $type = (string) ($d['type'] ?? '');
        if (! in_array($type, self::MANUAL, true)) {
            RuleViolation::fail(['type' => 'Tipo di movimento non valido.']);
        }
        $date = (string) ($d['movement_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $e['movement_date'] = 'Indica la data.';
        } elseif ($date < $a->purchase_date->format('Y-m-d')) {
            $e['movement_date'] = 'La data precede l’acquisto del cespite.';
        } elseif ($date > now()->addYear()->toDateString()) {
            $e['movement_date'] = 'Data troppo lontana nel futuro.';
        }
        if (trim((string) ($d['causale'] ?? '')) === '') {
            $e['causale'] = 'Indica la causale.';
        }
        if (in_array($a->status, ['venduto', 'eliminato'], true)) {
            $e['type'] = 'Il cespite è già '.($a->status === 'venduto' ? 'venduto' : 'eliminato').': nessun nuovo movimento (storna prima l’uscita).';
        }
        $e && RuleViolation::fail($e);
        $year = (int) substr($date, 0, 4);
        FiscalYears::assertOpen($c, $year, $e, 'movement_date');
        $valueType = ! in_array($type, ['trasferimento', 'riclassificazione'], true);
        $fixed = $a->depreciations()->whereIn('status', ['calcolato', 'contabilizzato'])->where('year', '>=', $year)->max('year');
        if ($valueType && $fixed) {
            $e['movement_date'] = "Ammortamento {$fixed} già calcolato: storna prima il calcolo di quell’esercizio, poi registra il movimento.";
        }
        $e && RuleViolation::fail($e);

        $out = ['type' => $type, 'movement_date' => $date, 'year' => $year, 'causale' => trim($d['causale']), 'lines' => [], 'values' => [], 'store' => [], 'asset_update' => [], 'warnings' => []];
        $acc = fn (string $r) => $a->account($r);
        $transit = $c->accounts()->where('code', AssetService::settings($c)['transit_account'])->value('id');
        $reserve = $c->accounts()->where('code', AssetService::settings($c)['reserve_account'])->value('id');
        switch ($type) {
            case 'incremento':
            case 'decremento':
                $amt = self::amount($d['amount'] ?? null, 'amount', $e);
                $fis = self::amount($d['fiscal_amount'] ?? null, 'fiscal_amount', $e, false);
                $e && RuleViolation::fail($e);
                if ($type === 'decremento') {
                    $v = AssetLedger::valuesAt($a, $date);
                    if ($amt > $v['cost_civil'] - $v['fund_civil']) {
                        RuleViolation::fail(['amount' => 'Il decremento supera il valore netto contabile ('.Decimal::fromMinor($v['cost_civil'] - $v['fund_civil']).').']);
                    }
                }
                $out['store'] = ['amount' => $amt, 'fiscal_amount' => $fis ?? $amt, 'plan_mode' => 'residuo'];
                $out['warnings'][] = 'Il costo entra in contabilità con la registrazione IVA della fattura sul conto dell’immobilizzazione: nessuna scrittura in prima nota cespiti. Il piano futuro si ricalcola sul nuovo valore.';
                break;
            case 'rivalutazione':
                $amt = self::amount($d['amount'] ?? null, 'amount', $e);
                $e && RuleViolation::fail($e);
                $recog = (bool) ($d['fiscal_recognized'] ?? false);
                if ($recog && trim((string) ($d['legal_basis'] ?? '')) === '') {
                    RuleViolation::fail(['legal_basis' => 'Il riconoscimento fiscale richiede la norma specifica di riferimento.']);
                }
                $out['store'] = ['amount' => $amt, 'fiscal_amount' => $recog ? $amt : 0, 'fiscal_recognized' => $recog, 'data' => ['legal_basis' => $d['legal_basis'] ?? null]];
                $out['lines'] = [['account_id' => $acc('asset'), 'dare' => $amt, 'description' => 'Rivalutazione '.$a->code], ['account_id' => $reserve, 'avere' => $amt, 'description' => 'Riserva di rivalutazione']];
                if (! $recog) {
                    $out['warnings'][] = 'Rivalutazione solo civilistica: il valore fiscale non cambia (differenza da considerare nelle variazioni fiscali).';
                }
                break;
            case 'svalutazione':
            case 'ripristino':
                $amt = self::amount($d['amount'] ?? null, 'amount', $e);
                $e && RuleViolation::fail($e);
                $v = AssetLedger::valuesAt($a, $date);
                if ($type === 'svalutazione' && $amt > $v['cost_civil'] - $v['fund_civil']) {
                    RuleViolation::fail(['amount' => 'La svalutazione supera il valore netto contabile.']);
                }
                if ($type === 'ripristino') {
                    $prev = (int) $a->movements()->where('status', 'registrato')->where('type', 'svalutazione')->get()->sum(fn ($m) => AssetLedger::m($m->amount))
                        - (int) $a->movements()->where('status', 'registrato')->where('type', 'ripristino')->get()->sum(fn ($m) => AssetLedger::m($m->amount));
                    if ($amt > $prev) {
                        RuleViolation::fail(['amount' => 'Il ripristino supera le svalutazioni registrate ('.Decimal::fromMinor($prev).').']);
                    }
                }
                $out['store'] = ['amount' => $amt, 'fiscal_amount' => 0];
                $out['lines'] = $type === 'svalutazione'
                    ? [['account_id' => $acc('writedown'), 'dare' => $amt, 'description' => 'Svalutazione '.$a->code], ['account_id' => $acc('fund'), 'avere' => $amt, 'description' => 'Fondo (svalutazione)']]
                    : [['account_id' => $acc('fund'), 'dare' => $amt, 'description' => 'Ripristino di valore '.$a->code], ['account_id' => $acc('gain'), 'avere' => $amt, 'description' => 'Ripristino di valore']];
                $out['warnings'][] = 'Svalutazioni e ripristini sono solo civilistici: non sono deducibili né imponibili automaticamente.';
                break;
            case 'rettifica_ammortamento':
                $amt = self::amount($d['amount'] ?? null, 'amount', $e, true, true);
                $fis = self::amount($d['fiscal_amount'] ?? null, 'fiscal_amount', $e, false, true);
                $e && RuleViolation::fail($e);
                $v = AssetLedger::valuesAt($a, $date);
                if ($v['cost_civil'] < $v['fund_civil'] + $amt || $v['fund_civil'] + $amt < 0) {
                    RuleViolation::fail(['amount' => 'Il fondo risultante supererebbe il costo o diventerebbe negativo.']);
                }
                $out['store'] = ['amount' => $amt, 'fiscal_amount' => $fis ?? 0, 'data' => ['previous_fund' => Decimal::fromMinor($v['fund_civil'])]];
                $out['lines'] = $amt >= 0
                    ? [['account_id' => $acc('expense'), 'dare' => $amt, 'description' => 'Rettifica ammortamento '.$a->code], ['account_id' => $acc('fund'), 'avere' => $amt]]
                    : [['account_id' => $acc('fund'), 'dare' => -$amt, 'description' => 'Rettifica ammortamento '.$a->code], ['account_id' => $acc('expense'), 'avere' => -$amt]];
                break;
            case 'alienazione_totale':
            case 'alienazione_parziale':
            case 'eliminazione':
                $share = 10000;
                if ($type === 'alienazione_parziale') {
                    $sp = trim(str_replace(',', '.', (string) ($d['share_pct'] ?? '')));
                    if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $sp) || (float) $sp <= 0 || (float) $sp >= 100) {
                        RuleViolation::fail(['share_pct' => 'Indica la quota alienata in percentuale (tra 0 e 100, esclusi).']);
                    }
                    $share = Decimal::toMinor($sp);
                }
                $price = $type === 'eliminazione' ? 0 : self::amount($d['price'] ?? null, 'price', $e);
                $costs = self::amount($d['sale_costs'] ?? null, 'sale_costs', $e, false) ?? 0;
                $vat = self::amount($d['vat'] ?? null, 'vat', $e, false);
                $e && RuleViolation::fail($e);
                $v = AssetLedger::valuesAt($a, $date);
                // Vendita parziale: la quota venduta non riceve quota nell'anno (fondo a inizio anno)
                $fundC = $type === 'alienazione_parziale' ? $v['fund_civil'] - $v['quota_civil'] : $v['fund_civil'];
                $fundF = $type === 'alienazione_parziale' ? $v['fund_fiscal'] - $v['quota_fiscal'] : $v['fund_fiscal'];
                $costC = DepreciationEngine::pct($v['cost_civil'], $share);
                $costF = DepreciationEngine::pct($v['cost_fiscal'], $share);
                $fundC = DepreciationEngine::pct($fundC, $share);
                $fundF = DepreciationEngine::pct($fundF, $share);
                $book = $costC - $fundC;
                $fbook = $costF - $fundF;
                $gain = $price - $costs - $book;
                $gainF = $price - $costs - $fbook;
                $out['values'] = ['cost_civil' => $costC, 'fund_civil' => $fundC, 'book_value' => $book, 'cost_fiscal' => $costF, 'fund_fiscal' => $fundF, 'fiscal_value' => $fbook,
                    'quota_civil' => $type === 'alienazione_parziale' ? 0 : $v['quota_civil'], 'gain_civil' => $gain, 'gain_fiscal' => $gainF];
                $out['store'] = ['price' => $price, 'vat' => $vat, 'sale_costs' => $costs, 'share_pct' => $type === 'alienazione_parziale' ? Decimal::fromMinor($share) : null,
                    'cost_civil' => $costC, 'fund_civil' => $fundC, 'book_value' => $book, 'cost_fiscal' => $costF, 'fund_fiscal' => $fundF, 'fiscal_value' => $fbook, 'gain_civil' => $gain, 'gain_fiscal' => $gainF,
                    'amount' => $costC];
                $lines = [['account_id' => $acc('fund'), 'dare' => $fundC, 'description' => 'Storno fondo '.$a->code]];
                if ($type !== 'eliminazione' && $price - $costs !== 0) {
                    $lines[] = ['account_id' => $transit, 'dare' => $price - $costs, 'description' => 'Corrispettivo netto della cessione'];
                }
                $lines[] = ['account_id' => $acc('asset'), 'avere' => $costC, 'description' => 'Storno costo '.$a->code];
                if ($gain > 0) {
                    $lines[] = ['account_id' => $acc('gain'), 'avere' => $gain, 'description' => 'Plusvalenza'];
                } elseif ($gain < 0) {
                    $lines[] = ['account_id' => $acc('loss'), 'dare' => -$gain, 'description' => $type === 'eliminazione' ? 'Perdita da eliminazione' : 'Minusvalenza'];
                }
                $out['lines'] = $lines;
                if ($v['quota_civil'] && $type !== 'alienazione_parziale') {
                    $out['warnings'][] = 'Il fondo comprende la quota dell’esercizio fino alla data ('.Decimal::fromMinor($v['quota_civil']).'): sarà contabilizzata con il calcolo ammortamenti '.$year.'.';
                }
                if ($a->fiscal_cost_cap !== null || $a->fiscal_deductible_pct !== null) {
                    $out['warnings'][] = 'Bene a deducibilità limitata: la plus/minusvalenza fiscale va rideterminata secondo le regole specifiche (art. 164 TUIR) – da verificare.';
                }
                if ($type !== 'eliminazione') {
                    $out['warnings'][] = 'Registra la fattura di vendita sul conto “Cessione cespiti (conto transitorio)”: così il corrispettivo non entra due volte nel conto economico.';
                }
                break;
            case 'riclassificazione':
                $cat = $c->assetCategories()->find($d['asset_category_id'] ?? 0);
                if (! $cat || $cat->id === $a->asset_category_id) {
                    RuleViolation::fail(['asset_category_id' => 'Scegli una nuova categoria.']);
                }
                if ($cat->kind !== $a->kind) {
                    RuleViolation::fail(['asset_category_id' => 'La nuova categoria ha una tipologia diversa (materiale/immateriale).']);
                }
                $v = AssetLedger::valuesAt($a, $date);
                $old = ['asset' => $a->account('asset'), 'fund' => $a->account('fund')];
                $new = ['asset' => $a->account_asset_id ?? $cat->account_asset_id, 'fund' => $a->account_fund_id ?? $cat->account_fund_id];
                if ($old['asset'] !== $new['asset'] && $v['cost_civil']) {
                    $out['lines'][] = ['account_id' => $new['asset'], 'dare' => $v['cost_civil'], 'description' => 'Riclassificazione '.$a->code];
                    $out['lines'][] = ['account_id' => $old['asset'], 'avere' => $v['cost_civil']];
                }
                if ($old['fund'] !== $new['fund'] && $v['fund_civil']) {
                    $out['lines'][] = ['account_id' => $old['fund'], 'dare' => $v['fund_civil'], 'description' => 'Riclassificazione fondo '.$a->code];
                    $out['lines'][] = ['account_id' => $new['fund'], 'avere' => $v['fund_civil']];
                }
                $from = max($year + 1, ((int) $a->depreciations()->where('status', '!=', 'stornato')->max('year')) + 1);
                $out['store'] = ['data' => ['from_category' => $a->asset_category_id, 'to_category' => $cat->id, 'rates_from_year' => $from]];
                $out['asset_update'] = ['asset_category_id' => $cat->id];
                $out['rates'] = ['from_year' => $from, 'civil' => (string) ($cat->civil_rate ?? $cat->fiscal_rate), 'fiscal' => (string) $cat->fiscal_rate];
                $out['warnings'][] = "Le aliquote della nuova categoria valgono dall’esercizio {$from}; gli esercizi già calcolati restano invariati.";
                break;
            case 'trasferimento':
                $cc = trim((string) ($d['cost_center'] ?? ''));
                if ($cc === '' || $cc === (string) $a->cost_center) {
                    RuleViolation::fail(['cost_center' => 'Indica il nuovo centro di costo.']);
                }
                $out['store'] = ['data' => ['from' => $a->cost_center, 'to' => $cc, 'location' => $d['location'] ?? null]];
                $out['asset_update'] = array_filter(['cost_center' => $cc, 'location' => $d['location'] ?? null]);
                break;
        }

        return $out;
    }

    public function register(Company $c, User $actor, Asset $a, array $d): AssetMovement
    {
        return DB::transaction(function () use ($c, $actor, $a, $d) {
            $a = Asset::whereKey($a->id)->lockForUpdate()->first();
            $b = $this->build($c, $a, $d);
            if ($b['lines'] && ! ($d['confirm'] ?? false)) {
                RuleViolation::fail(['confirm' => 'Controlla la scrittura proposta e conferma la contabilizzazione.']);
            }
            $s = $b['store'];
            $mon = fn ($k) => isset($s[$k]) && $s[$k] !== null ? Decimal::fromMinor($s[$k]) : null;
            $m = AssetMovement::create(['company_id' => $c->id, 'asset_id' => $a->id, 'type' => $b['type'], 'movement_date' => $b['movement_date'], 'year' => $b['year'],
                'amount' => $mon('amount'), 'fiscal_amount' => $mon('fiscal_amount'), 'price' => $mon('price'), 'vat' => $mon('vat'), 'sale_costs' => $mon('sale_costs'), 'book_value' => $mon('book_value'),
                'fiscal_value' => $mon('fiscal_value'), 'gain_civil' => $mon('gain_civil'), 'gain_fiscal' => $mon('gain_fiscal'), 'fund_civil' => $mon('fund_civil'), 'fund_fiscal' => $mon('fund_fiscal'),
                'cost_civil' => $mon('cost_civil'), 'cost_fiscal' => $mon('cost_fiscal'), 'share_pct' => $s['share_pct'] ?? null, 'plan_mode' => $s['plan_mode'] ?? null,
                'fiscal_recognized' => $s['fiscal_recognized'] ?? false, 'data' => ($s['data'] ?? []) + ['before' => $a->only(['asset_category_id', 'cost_center', 'location', 'status'])],
                'causale' => $b['causale'], 'invoice_id' => $d['invoice_id'] ?? null, 'document_id' => $d['document_id'] ?? null, 'created_by' => $actor->id]);
            if ($b['lines']) {
                $entry = $this->journal->post($c, $actor, 'mov:'.$m->id, $b['movement_date'], self::TYPES[$b['type']].' · '.$a->code.' '.$a->description, $b['lines']);
                $m->update(['journal_entry_id' => $entry->id]);
            }
            if ($b['asset_update']) {
                $a->update([...$b['asset_update'], 'version' => $a->version + 1]);
            }
            if (isset($b['rates'])) {
                AssetRate::updateOrCreate(['asset_id' => $a->id, 'from_year' => $b['rates']['from_year']], ['civil_rate' => $b['rates']['civil'], 'fiscal_rate' => $b['rates']['fiscal'],
                    'reason' => 'Riclassificazione: '.$b['causale'], 'created_by' => $actor->id]);
            }
            app(AssetLedger::class)->refreshStatus($a);
            Audit::record('asset.movement', $actor, $c, $a, $b['causale'], ['type' => $b['type'], 'date' => $b['movement_date'], 'movement' => $m->id,
                'gain_civil' => isset($s['gain_civil']) ? Decimal::fromMinor($s['gain_civil']) : null]);

            return $m;
        });
    }

    /** Storno di un movimento: registrazione inversa, stato del cespite come prima. */
    public function reverse(Company $c, User $actor, AssetMovement $m, string $reason): AssetMovement
    {
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo dello storno.']);
        }
        if ($m->status !== 'registrato' || in_array($m->type, ['acquisizione', 'ammortamento'], true)) {
            RuleViolation::fail(['movement' => in_array($m->type, ['acquisizione', 'ammortamento'], true) ? 'Questo movimento si storna dal calcolo ammortamenti o eliminando il cespite.' : 'Movimento già stornato.']);
        }
        FiscalYears::assertOpen($c, $m->year, field: 'movement');

        return DB::transaction(function () use ($c, $actor, $m, $reason) {
            $a = Asset::whereKey($m->asset_id)->lockForUpdate()->first();
            $later = $a->depreciations()->whereIn('status', ['calcolato', 'contabilizzato'])->where('year', '>=', $m->year)->max('year');
            if ($later && ! in_array($m->type, ['trasferimento'], true)) {
                RuleViolation::fail(['movement' => "Ammortamento {$later} già calcolato dopo il movimento: storna prima il calcolo."]);
            }
            if ($m->journal_entry_id) {
                $this->journal->reverse($c, $actor, $m->journal()->first(), now()->toDateString(), $reason);
            }
            $m->update(['status' => 'stornato']);
            $before = $m->data['before'] ?? [];
            if ($m->type === 'riclassificazione') {
                $a->update(['asset_category_id' => $before['asset_category_id'] ?? $a->asset_category_id, 'version' => $a->version + 1]);
                AssetRate::where('asset_id', $a->id)->where('from_year', $m->data['rates_from_year'] ?? 0)->delete();
            }
            if ($m->type === 'trasferimento') {
                $a->update(['cost_center' => $before['cost_center'] ?? null, 'location' => $before['location'] ?? $a->location, 'version' => $a->version + 1]);
            }
            app(AssetLedger::class)->refreshStatus($a);
            Audit::record('asset.movement_reversed', $actor, $c, $a, $reason, ['movement' => $m->id, 'type' => $m->type]);

            return $m;
        });
    }
}
