<?php

namespace App\Domain;

use App\Domain\Liquidazioni\SettlementService;
use App\Domain\Scadenziario\FiscalService;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AssetDepreciation;
use App\Models\AssetMovement;
use App\Models\Company;
use App\Models\FiscalEntry;
use App\Models\Installment;
use App\Models\NonVatEntry;
use App\Models\VatRegistration;
use App\Support\Decimal;
use App\Support\Sezione;

/**
 * Conto economico (indicazione del committente, 21.11.2026): valutazione di costi e ricavi per sezione e conto per un anno solare, dai dati esistenti
 * (registrazioni IVA, registrazioni fuori campo, pagamenti). Non è contabilità generale: niente ammortamenti, ratei/risconti o rimanenze.
 *
 * - competenza: data della registrazione IVA (ricavo = imponibile, costo = imponibile + IVA non detraibile);
 * - cassa: incassi e pagamenti dello Scadenziario, ripartiti proporzionalmente sulle righe imputate (pagamenti parziali cumulati, arrotondamento come in FiscalService);
 * - fiscale: l'attribuzione fiscale (`fiscal_entries`): professionisti e semplificata per cassa al pagamento, semplificata per competenza alla
 *   data di registrazione (presunzione); con limite di deducibilità del conto. Gli importi non attribuibili compaiono separatamente sotto «Da verificare».
 * Fuori campo IVA conta in tutte le viste alla data di registrazione. I documenti di integrazione TD16–TD19 contribuiscono solo con l'IVA non detraibile: la loro
 * base imponibile è nel documento originale.
 */
final class IncomeStatementService
{
    public const VIEWS = ['competenza' => 'Per competenza', 'cassa' => 'Per cassa', 'fiscale' => 'Ai fini fiscali'];

    private const INTEGRATION = ['TD16', 'TD17', 'TD18', 'TD19'];

    /** @var array<int, array{amount: int, fiscal: int, count: int}> */
    private array $acc = [];

    /** @var array<string, int> */
    private array $other = [];

    /** @var array<int, true> Conti dello Stato patrimoniale (Cespiti): acquisto e vendita non sono costo/ricavo */
    private array $patr = [];

    public function build(Company $company, int $year, string $view): array
    {
        $this->acc = [];
        $this->patr = array_fill_keys(Account::where('company_id', $company->id)->where('type', 'patrimoniale')->pluck('id')->all(), true);
        $this->other = ['da_verificare' => 0, 'non_registrate' => 0];
        $pending = [];
        $notes = [];
        [$mode, $modeWhy] = FiscalService::modeFor($company, $year);

        if ($view === 'competenza') {
            foreach ($this->registrations($company)->whereBetween('registration_date', ["{$year}-01-01", "{$year}-12-31"])->get() as $r) {
                foreach ($this->components($r) as $c) {
                    $this->add($c['account_id'], $c['amount'], $c['amount']);
                }
            }
            $criterion = 'Data di registrazione IVA: ricavi e costi delle fatture registrate nell’anno (imponibile; per i costi più l’IVA non detraibile).';
        } elseif ($view === 'cassa') {
            $this->cash($company, $year);
            $criterion = 'Data di incasso e pagamento nello Scadenziario: ogni pagamento è ripartito sui conti della fattura in proporzione al totale da pagare.';
            if ($this->other['non_registrate']) {
                $notes[] = 'Incassi e pagamenti di fatture non ancora registrate ('.SettlementService::eur($this->other['non_registrate']).' €): non entrano nel conto economico finché la fattura non è registrata.';
            }
        } else {
            $criterion = $modeWhy;
            $entries = FiscalEntry::where('company_id', $company->id)->where('year', $year)->with(['invoice', 'registration.lines'])->orderBy('event_date')->get();
            foreach ($entries as $e) {
                $amount = Decimal::toMinor((string) $e->amount);
                $fiscal = Decimal::toMinor((string) $e->fiscal_amount);
                if (in_array($e->invoice->doc_type, self::INTEGRATION, true)) {
                    // Solo la quota di IVA non deducibile della riga di integrazione è costo.
                    $l = $e->registration?->lines->values()[$e->component] ?? null;
                    $t = $l ? Decimal::toMinor((string) $l->taxable) : 0;
                    $nd = $l ? Decimal::toMinor((string) $l->tax) - Decimal::toMinor((string) $l->deductible_tax) : 0;
                    $amount = $t + $nd ? intdiv($amount * $nd, $t + $nd) : 0;
                    $fiscal = $t + $nd ? intdiv($fiscal * $nd, $t + $nd) : 0;
                }
                if ($amount === 0 && $fiscal === 0) {
                    continue;
                }
                if ($e->basis === 'da_verificare') {
                    $this->other['da_verificare'] += $amount;
                    $pending[$e->invoice_id] ??= ['invoice_id' => $e->invoice_id, 'number' => $e->invoice->number, 'nature' => $e->nature, 'amount' => 0, 'reason' => $e->reason];
                    $pending[$e->invoice_id]['amount'] += $amount;

                    continue;
                }
                $this->add($e->account_id, $amount, $fiscal);
            }
            if ($mode === 'da_verificare') {
                $notes[] = 'Criterio di imputazione non determinabile per il '.$year.': le voci restano “da verificare” finché il regime non è confermato.';
            }
        }

        // Fuori campo IVA: in tutte le viste alla data della registrazione del movimento.
        foreach (NonVatEntry::where('company_id', $company->id)->whereBetween('entry_date', ["{$year}-01-01", "{$year}-12-31"])->with('account')->get() as $n) {
            $c = $n->sign * Decimal::toMinor((string) $n->amount);
            if ($view === 'fiscale' && $mode === 'da_verificare') {
                $this->other['da_verificare'] += $c;

                continue;
            }
            $this->add($n->account_id, $c, $view === 'fiscale' ? self::deductible($c, $n->account) : $c);
        }

        $this->assets($company, $year, $view, $notes);

        return $this->assemble($company, $year, $view, $criterion, $notes, array_values(array_map(fn ($p) => [...$p, 'amount' => Decimal::fromMinor($p['amount'])], $pending)), $mode);
    }

    /** Cespiti: ammortamenti (civilistici/fiscali), svalutazioni, ripristini, plusvalenze e minusvalenze dell'anno; non nella vista per cassa. */
    private function assets(Company $c, int $year, string $view, array &$notes): void
    {
        $dep = AssetDepreciation::where('company_id', $c->id)->where('year', $year)->where('status', '!=', 'stornato')->with('asset.category')->get();
        $mov = AssetMovement::where('company_id', $c->id)->where('year', $year)->where('status', 'registrato')
            ->whereIn('type', ['alienazione_totale', 'alienazione_parziale', 'eliminazione', 'svalutazione', 'ripristino'])->with('asset.category')->get();
        if ($view === 'cassa') {
            if ($dep->isNotEmpty() || $mov->isNotEmpty()) {
                $notes[] = 'Ammortamenti e plus/minusvalenze dei cespiti non sono movimenti di cassa: compaiono nelle viste per competenza e ai fini fiscali.';
            }

            return;
        }
        $m = fn ($v) => $v === null ? 0 : Decimal::toMinor((string) $v);
        $extra = 0;
        foreach ($dep as $d) {
            $this->add($d->asset->account('expense'), $m($d->civil_amount), $m($d->fiscal_amount));
            $extra += $m($d->extra_deduction);
        }
        foreach ($mov as $x) {
            $a = $x->asset;
            match ($x->type) {
                'svalutazione' => $this->add($a->account('writedown'), $m($x->amount), 0),
                'ripristino' => $this->add($a->account('gain'), $m($x->amount), 0),
                default => $m($x->gain_civil) >= 0
                    ? $this->add($a->account('gain'), $m($x->gain_civil), $m($x->gain_fiscal))
                    : $this->add($a->account('loss'), -$m($x->gain_civil), -$m($x->gain_fiscal)),
            };
        }
        if ($dep->contains(fn ($d) => $d->status === 'calcolato')) {
            $notes[] = 'Comprende ammortamenti calcolati ma non ancora contabilizzati.';
        }
        if ($extra && $view === 'fiscale') {
            $this->other['maggiorazioni'] = $extra;
            $notes[] = 'Maggiorazioni (super/iperammortamento) '.SettlementService::eur($extra).' €: variazione in diminuzione extracontabile (stampa cespiti K), già sottratta nel risultato fiscale dopo le variazioni.';
        }
    }

    private function registrations(Company $company)
    {
        return VatRegistration::where('company_id', $company->id)->with(['lines', 'invoice:id,doc_type']);
    }

    /** Componenti imputate di una registrazione in centesimi (con segno della registrazione). */
    private function components(VatRegistration $r): array
    {
        $integration = in_array($r->invoice?->doc_type, self::INTEGRATION, true);
        $sale = $r->direction->value === 'vendita';
        $out = [];
        foreach ($r->lines->values() as $k => $l) {
            if (isset($this->patr[$l->account_id])) {
                continue;
            }
            $t = Decimal::toMinor((string) $l->taxable);
            $nd = $sale ? 0 : Decimal::toMinor((string) $l->tax) - Decimal::toMinor((string) $l->deductible_tax);
            $out[] = ['k' => $k, 'account_id' => $l->account_id, 'amount' => $r->sign * (($integration ? 0 : $t) + $nd)];
        }

        return $out;
    }

    /** Per cassa: ogni attribuzione valida nell'anno ripartisce la propria quota sulle componenti della registrazione valida. */
    private function cash(Company $company, int $year): void
    {
        $instByInvoice = Installment::where('company_id', $company->id)->get()->groupBy('invoice_id');
        $allocs = Allocation::where('company_id', $company->id)->where('status', 'valida')
            ->where(fn ($w) => $w->whereNull('movement_id')->orWhereHas('movement', fn ($m) => $m->where('status', 'valida')))
            ->orderBy('allocation_date')->orderBy('id')->get();
        $invOf = [];
        foreach ($instByInvoice as $invId => $list) {
            foreach ($list as $i) {
                $invOf[$i->id] = $invId;
            }
        }
        $byInvoice = [];
        foreach ($allocs as $a) {
            foreach (array_unique(array_filter([$a->installment_id, $a->credit_installment_id])) as $iid) {
                if (isset($invOf[$iid])) {
                    $byInvoice[$invOf[$iid]][] = $a;
                }
            }
        }
        $regs = VatRegistration::where('company_id', $company->id)->whereNotNull('active_invoice_id')->whereIn('active_invoice_id', array_keys($byInvoice))->with(['lines', 'invoice:id,doc_type,taxable_total'])->get()->keyBy('active_invoice_id');
        foreach ($byInvoice as $invId => $list) {
            $payable = $instByInvoice[$invId]->sum(fn ($i) => Decimal::toMinor((string) $i->amount));
            $reg = $regs[$invId] ?? null;
            $comps = $reg ? $this->components($reg) : [];
            $done = array_fill(0, count($comps), 0);
            $settled = 0;
            foreach ($list as $a) {
                $settled += Decimal::toMinor((string) $a->amount);
                $inYear = (int) $a->allocation_date->format('Y') === $year;
                if (! $reg) {
                    if ($inYear) {
                        $this->other['non_registrate'] += Decimal::toMinor((string) $a->amount);
                    }

                    continue;
                }
                foreach ($comps as $k => $c) {
                    $target = $payable <= 0 || $settled >= $payable ? $c['amount'] : self::share($c['amount'], $settled, $payable);
                    $delta = $target - $done[$k];
                    $done[$k] = $target;
                    if ($inYear && $delta !== 0) {
                        $this->add($c['account_id'], $delta, $delta);
                    }
                }
            }
        }
    }

    private static function share(int $amount, int $settled, int $payable): int
    {
        $r = intdiv(abs($amount) * $settled * 2 + $payable, 2 * $payable);

        return $amount < 0 ? -$r : $r;
    }

    private static function deductible(int $c, ?Account $a): int
    {
        $pct = $a?->deducibilita_pct;
        if ($pct === null) {
            return $c;
        }
        $p = Decimal::toMinor((string) $pct);

        return intdiv(abs($c) * $p * 2 + 10000, 20000) * ($c < 0 ? -1 : 1);
    }

    private function add(?int $accountId, int $amount, int $fiscal): void
    {
        $k = $accountId ?? 0;
        $this->acc[$k] ??= ['amount' => 0, 'fiscal' => 0];
        $this->acc[$k]['amount'] += $amount;
        $this->acc[$k]['fiscal'] += $fiscal;
    }

    private function assemble(Company $company, int $year, string $view, string $criterion, array $notes, array $pending, string $mode): array
    {
        $accounts = Account::where('company_id', $company->id)->whereIn('id', array_keys($this->acc))->get()->keyBy('id');
        $order = array_flip(array_keys(Sezione::LABELS));
        $sides = ['ricavo' => [], 'costo' => []];
        foreach ($this->acc as $id => $v) {
            if ($v['amount'] === 0 && $v['fiscal'] === 0) {
                continue;
            }
            $a = $accounts[$id] ?? null;
            $type = $a?->type ?? 'costo';
            $sez = $a?->sezione ?? '';
            $g = &$sides[$type][$sez];
            $g ??= ['key' => $sez, 'label' => $sez !== '' ? (Sezione::LABELS[$sez] ?? $sez) : 'Senza sezione', 'amount' => 0, 'fiscal' => 0, 'rows' => [], 'order' => $order[$sez] ?? 999];
            $g['rows'][] = ['account_id' => $a?->id, 'code' => $a?->code ?? '—', 'name' => $a?->name ?? 'Conto non indicato', 'amount' => $v['amount'], 'fiscal' => $v['fiscal']];
            $g['amount'] += $v['amount'];
            $g['fiscal'] += $v['fiscal'];
            unset($g);
        }
        $tot = ['ricavi' => 0, 'costi' => 0, 'ricavi_fiscali' => 0, 'costi_fiscali' => 0];
        $out = [];
        foreach ($sides as $type => $groups) {
            usort($groups, fn ($x, $y) => [$x['order'], $x['label']] <=> [$y['order'], $y['label']]);
            foreach ($groups as &$g) {
                usort($g['rows'], fn ($x, $y) => strcmp($x['code'], $y['code']));
                $tot[$type === 'ricavo' ? 'ricavi' : 'costi'] += $g['amount'];
                $tot[$type === 'ricavo' ? 'ricavi_fiscali' : 'costi_fiscali'] += $g['fiscal'];
                $g['rows'] = array_map(fn ($r) => [...$r, 'amount' => Decimal::fromMinor($r['amount']), 'fiscal' => Decimal::fromMinor($r['fiscal'])], $g['rows']);
                $g = [...$g, 'amount' => Decimal::fromMinor($g['amount']), 'fiscal' => Decimal::fromMinor($g['fiscal'])];
                unset($g['order']);
            }
            unset($g);
            $out[$type === 'ricavo' ? 'ricavi' : 'costi'] = array_values($groups);
        }
        $tot['risultato'] = $tot['ricavi'] - $tot['costi'];
        $tot['risultato_fiscale'] = $tot['ricavi_fiscali'] - $tot['costi_fiscali'];
        $tot['maggiorazioni'] = $view === 'fiscale' ? ($this->other['maggiorazioni'] ?? 0) : 0;
        $tot['risultato_dopo_variazioni'] = $tot['risultato_fiscale'] - $tot['maggiorazioni'];
        if ($view === 'competenza' || $view === 'cassa') {
            $notes[] = 'Documenti di integrazione TD16–TD19: conta solo l’IVA non detraibile, il costo è nel documento originale.';
        }

        return ['year' => $year, 'view' => $view, 'views' => self::VIEWS, 'criterion' => $criterion, 'mode' => $mode, 'ricavi' => $out['ricavi'], 'costi' => $out['costi'],
            'totals' => array_map(fn ($v) => Decimal::fromMinor($v), $tot), 'da_verificare' => Decimal::fromMinor($this->other['da_verificare']), 'pending' => $pending, 'notes' => $notes];
    }
}
