<?php

namespace App\Domain;

use App\Domain\Esterometro\CrossBorderService;
use App\Domain\Liquidazioni\SettlementCalculator;
use App\Domain\Scadenziario\Ledger;
use App\Domain\Scadenziario\ScadenziarioQuery;
use App\Domain\Scadenziario\ScheduleService;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\InvoiceController;
use App\Models\Company;
use App\Models\Installment;
use App\Models\InvoiceReprocessing;
use App\Models\MoneyMovement;
use App\Models\VatRegistration;
use App\Models\VatSettlement;
use App\Support\Decimal;
use App\Support\VatPeriod;

/**
 * Panoramica (indicazione del committente, 19.11.2026): attività aperte, registrazioni mancanti e prossime scadenze.
 * Legge soltanto; ogni riga porta alla pagina in cui l'attività viene svolta.
 */
final class DashboardService
{
    public function __construct(private ScadenziarioQuery $query, private CrossBorderService $xb) {}

    /** Periodo scaduto con registrazioni ma senza liquidazione confermata (i periodi vuoti non contano). */
    private static function needsSettlement(Company $company, int $year, int $period): bool
    {
        return ! SettlementCalculator::latest($company, $year, $period)
            && VatRegistration::where('company_id', $company->id)->where('year', $year)->where('period', $period)->exists();
    }

    public function build(Company $company, ?int $activeYear = null): array
    {
        $base = "/aziende/{$company->id}";
        $today = now()->toDateString();
        $year = $activeYear ?? (int) now()->year;
        $tasks = [];
        $task = function (string $key, int $count, string $label, string $url, string $tone = 'warn') use (&$tasks) {
            if ($count > 0) {
                $tasks[] = compact('key', 'count', 'label', 'url', 'tone');
            }
        };

        // Regime
        $profile = $company->activeVatProfile($year);
        if (! $profile) {
            $tasks[] = ['key' => 'regime', 'count' => 1, 'label' => "Regime IVA {$year} da confermare: senza regime non si registra e non si liquida.", 'url' => "{$base}/profilo-iva?anno={$year}", 'tone' => 'bad'];
        }

        // Registrazioni mancanti
        $open = $company->invoices()->whereIn('status', [InvoiceStatus::Importata, InvoiceStatus::DaVerificare]);
        $task('da_registrare', (clone $open)->count(), 'Fatture da registrare', "{$base}/fatture?stato=aperte");
        $task('da_verificare', (clone $open)->where('status', InvoiceStatus::DaVerificare)->count(), 'Fatture con segnalazioni da verificare', "{$base}/fatture?stato=da_verificare");
        $repro = InvoiceReprocessing::where('company_id', $company->id)->whereIn('status', ['in_attesa', 'da_abbinare'])->orderBy('id');
        $task('rielaborazioni', (clone $repro)->count(), 'Differenze da “Rielabora XML” da confermare', ($r = (clone $repro)->first()) ? "{$base}/rielaborazioni/{$r->id}" : "{$base}/importa");

        // Fatture ricorrenti: modelli in scadenza (senza emissione automatica attendono «Emetti ora»)
        $task('ricorrenti', $company->recurringInvoices()->where('active', true)->where('next_date', '<=', $today)->where(fn ($q) => $q->whereNull('end_date')->orWhereColumn('end_date', '>=', 'next_date'))->count(), 'Fatture ricorrenti da emettere', "{$base}/fatture/ricorrenti");

        // Operazioni transfrontaliere
        $xbRows = $this->xb->rows($company, null);
        $task('integrazioni', collect($xbRows)->where('state', 'integrazione_mancante')->count(), 'Acquisti dall’estero senza integrazione IVA (TD17–TD19)', "{$base}/operazioni-transfrontaliere?anno=tutti");
        $task('xml_scaduti', collect($xbRows)->where('overdue', true)->count(), 'Operazioni transfrontaliere scadute senza XML', "{$base}/operazioni-transfrontaliere?anno=tutti", 'bad');

        // Liquidazioni: periodi scaduti dell'anno senza liquidazione confermata, e da verificare
        if ($profile) {
            $per = $profile->periodicity->value;
            $missing = 0;
            $first = null;
            for ($p = 1; $p <= VatPeriod::max($per); $p++) {
                if (VatPeriod::end($year, $p, $per) >= $today) {
                    break;
                }
                if (self::needsSettlement($company, $year, $p)) {
                    $missing++;
                    $first ??= $p;
                }
            }
            $task('liquidazioni', $missing, 'Liquidazioni IVA di periodi chiusi da confermare', $first ? "{$base}/liquidazioni/{$year}/{$first}" : "{$base}/liquidazioni");
        }
        $review = VatSettlement::where('company_id', $company->id)->where('status', 'confermata')->where('review_required', true)->count();
        $task('riesame', $review, 'Liquidazioni confermate da riesaminare (dati cambiati dopo la conferma)', "{$base}/liquidazioni?anno=tutti", 'bad');

        // Registrazioni mancanti: fatture aperte più vecchie
        $missingRows = (clone $open)->orderBy('doc_date')->limit(6)->get()->map(fn ($i) => [
            'id' => $i->id, 'number' => $i->number, 'doc_date' => $i->doc_date->toDateString(), 'direction' => $i->direction?->value,
            'direction_label' => $i->direction?->label() ?? 'Da definire', 'counterparty' => $i->direction === null ? $i->supplier_name : $i->counterpartyName(),
            'total' => (string) $i->total_amount, 'status' => $i->status->value, 'url' => "{$base}/fatture/{$i->id}?vista=1&ritorno=".urlencode($base),
        ])->all();

        // Prossime scadenze: rate aperte e termini fiscali, per data
        $due = [];
        $inst = Installment::where('company_id', $company->id)->whereNotNull('due_date')->where('due_date', '<=', now()->addDays(45)->toDateString())
            ->whereHas('invoice', fn ($w) => $w->whereNotIn('status', [InvoiceStatus::Scartata, InvoiceStatus::Archiviata]))->with('invoice')->orderBy('due_date')->limit(40)->get();
        foreach ($inst as $i) {
            $s = Ledger::installmentState($i);
            if ($s['residual'] <= 0) {
                continue;
            }
            $sale = $i->invoice->direction === InvoiceDirection::Vendita;
            $due[] = ['date' => $i->due_date->toDateString(), 'kind' => $sale ? 'Da incassare' : 'Da pagare', 'label' => ($sale ? 'Incasso ' : 'Pagamento ').$i->invoice->number.' · '.ScheduleService::partyName($i->invoice),
                'amount' => Decimal::fromMinor($s['residual']), 'overdue' => $i->due_date->toDateString() < $today, 'url' => "{$base}/fatture/{$i->invoice_id}#scadenze"];
        }
        foreach ($this->query->fiscalDeadlines($company) as $f) {
            if ($f['state'] === 'aperta' && $f['date']) {
                $due[] = ['date' => $f['date'], 'kind' => 'Scadenza fiscale', 'label' => $f['label'].($f['code'] ? " · codice {$f['code']}" : ''), 'amount' => $f['amount'],
                    'overdue' => $f['date'] < $today, 'url' => $f['type'] === 'liquidazione' ? "{$base}/liquidazioni/{$f['year']}/{$f['period']}" : "{$base}/scadenziario/scadenze-fiscali"];
            }
        }
        foreach ($xbRows as $r) {
            if (! $r['exclusion'] && ! $r['xml'] && in_array($r['state'], ['integrata', 'non_rilevante', 'da_trasmettere', 'integrazione_mancante'], true) && $r['deadline'] <= now()->addDays(45)->toDateString()) {
                $due[] = ['date' => $r['deadline'], 'kind' => 'Operazione transfrontaliera', 'label' => "XML {$r['xml_doc_type']} · {$r['number']} · {$r['partner']}", 'amount' => $r['taxable'],
                    'overdue' => $r['overdue'], 'url' => "{$base}/operazioni-transfrontaliere?anno=tutti"];
            }
        }
        usort($due, fn ($a, $b) => $a['date'] <=> $b['date']);

        // I moduli disattivati (Funzioni e opzioni) non compaiono
        $mods = CompanyOptions::for($company)['modules'];
        $hideTasks = array_merge($mods['adempimenti'] ? [] : ['liquidazioni', 'riesame', 'integrazioni', 'xml_scaduti'], $mods['emissione'] ? [] : ['ricorrenti']);
        $tasks = array_values(array_filter($tasks, fn ($t) => ! in_array($t['key'], $hideTasks, true)));
        $hideKinds = array_merge($mods['scadenziario'] ? [] : ['Da incassare', 'Da pagare'], $mods['adempimenti'] ? [] : ['Scadenza fiscale', 'Operazione transfrontaliera']);
        $due = array_values(array_filter($due, fn ($d) => ! in_array($d['kind'], $hideKinds, true)));
        $areas = array_values(array_filter($this->areas($company, $year, $base, $today), fn ($a) => ! (($a['id'] === 'fatturazione' && ! $mods['emissione']) || ($a['id'] === 'incassi' && ! $mods['scadenziario']) || ($a['id'] === 'iva' && ! $mods['adempimenti']))));

        return ['tasks' => $tasks, 'missing' => $missingRows, 'missing_total' => (clone $open)->count(), 'deadlines' => array_slice($due, 0, 10), 'today' => $today,
            'year' => $year, 'areas' => $areas, 'analysis' => $this->analysis($company, $year)];
    }

    /**
     * Situazione per gruppo di menu (Fatture, Contabilità, Incassi e pagamenti, Adempimenti fiscali, Tabelle; decisione n. 82): numeri per l'anno contabile scelto
     * e lavoro aperto, ogni riga porta alla pagina.
     *
     * @return list<array{id: string, label: string, url: string, items: list<array{label: string, value: string, tone: string, url: string}>}>
     */
    private function areas(Company $company, int $year, string $base, string $today): array
    {
        $y = fn ($q) => $q->whereBetween('doc_date', ["{$year}-01-01", "{$year}-12-31"]);
        $it = fn (string $label, int|string $value, string $url, string $tone = 'neutral') => ['label' => $label, 'value' => (string) $value, 'tone' => $tone, 'url' => $url];
        $issued = $y($company->invoices()->where('source', 'emessa'));
        $toSend = (clone $issued)->whereNull('transmitted_at')->count();
        $recDue = $company->recurringInvoices()->where('active', true)->where('next_date', '<=', $today)->where(fn ($q) => $q->whereNull('end_date')->orWhereColumn('end_date', '>=', 'next_date'))->count();
        $open = $company->invoices()->whereIn('status', [InvoiceStatus::Importata, InvoiceStatus::DaVerificare]);
        $sales = $y($company->invoices()->where('direction', 'vendita')->where('status', '!=', 'archiviata'))->count();
        $purchases = InvoiceController::yearScope($company->invoices()->where('direction', 'acquisto')->where('status', '!=', 'archiviata'), $year)->count();
        $recv = 0;
        $pay = 0;
        $overR = 0;
        $overP = 0;
        $inst = Installment::where('company_id', $company->id)->whereHas('invoice', fn ($w) => $w->whereNotIn('status', [InvoiceStatus::Scartata, InvoiceStatus::Archiviata]))->with('invoice')->get();
        foreach ($inst as $i) {
            $res = Ledger::installmentState($i)['residual'];
            if ($res <= 0 || ! $i->invoice->direction) {
                continue;
            }
            $sale = $i->invoice->direction === InvoiceDirection::Vendita;
            $late = $i->due_date && $i->due_date->toDateString() < $today;
            $sale ? $recv += $res : $pay += $res;
            if ($late) {
                $sale ? $overR += $res : $overP += $res;
            }
        }
        $eur = fn (int $c) => number_format($c / 100, 2, ',', '.').' €';
        $liqOpen = 0;
        if ($profile = $company->activeVatProfile($year)) {
            $per = $profile->periodicity->value;
            for ($p = 1; $p <= VatPeriod::max($per) && VatPeriod::end($year, $p, $per) < $today; $p++) {
                $liqOpen += self::needsSettlement($company, $year, $p) ? 1 : 0;
            }
        }
        $xbOpen = collect($this->xb->rows($company, $year))->filter(fn ($r) => in_array($r['state'], ['integrazione_mancante'], true) || $r['overdue'])->count();

        return [
            ['id' => 'fatturazione', 'label' => 'Fatture', 'url' => "{$base}/fatture-elettroniche", 'items' => [
                $it('Fatture emesse', (clone $issued)->count(), "{$base}/fatture-elettroniche"), $it('Da trasmettere', $toSend, "{$base}/fatture-elettroniche?stato=da_trasmettere", $toSend ? 'warn' : 'good'),
                $it('Ricorrenti da emettere', $recDue, "{$base}/fatture/ricorrenti", $recDue ? 'warn' : 'good')]],
            ['id' => 'incassi', 'label' => 'Incassi e pagamenti', 'url' => "{$base}/scadenziario/da-incassare", 'items' => [
                $it('Da incassare', $eur($recv), "{$base}/scadenziario/da-incassare"), $it('di cui scaduti', $eur($overR), "{$base}/scadenziario/da-incassare", $overR ? 'bad' : 'good'),
                $it('Da pagare', $eur($pay), "{$base}/scadenziario/da-pagare"), $it('di cui scaduti', $eur($overP), "{$base}/scadenziario/da-pagare", $overP ? 'bad' : 'good')]],
            ['id' => 'documenti', 'label' => 'Contabilità', 'url' => "{$base}/fatture?stato=aperte", 'items' => [
                $it('Da registrare', (clone $open)->count(), "{$base}/fatture?stato=aperte", (clone $open)->count() ? 'warn' : 'good'), $it('Vendite', $sales, "{$base}/vendite"), $it('Acquisti', $purchases, "{$base}/acquisti"),
                $it('Con segnalazioni', (clone $open)->where('status', InvoiceStatus::DaVerificare)->count(), "{$base}/fatture?stato=da_verificare")]],
            ['id' => 'iva', 'label' => 'Adempimenti fiscali', 'url' => "{$base}/liquidazioni", 'items' => [
                $it('Liquidazioni da confermare', $liqOpen, "{$base}/liquidazioni", $liqOpen ? 'warn' : 'good'), $it('Operazioni transfrontaliere da fare', $xbOpen, "{$base}/operazioni-transfrontaliere", $xbOpen ? 'warn' : 'good'),
                $it('Registrazioni nell’anno', VatRegistration::where('company_id', $company->id)->where('year', $year)->where('status', 'registrata')->count(), "{$base}/registri/vendite")]],
            ['id' => 'anagrafiche', 'label' => 'Tabelle', 'url' => "{$base}/anagrafiche/clienti", 'items' => [
                $it('Clienti', $company->partners()->where('is_customer', true)->count(), "{$base}/anagrafiche/clienti"), $it('Fornitori', $company->partners()->where('is_supplier', true)->count(), "{$base}/anagrafiche/fornitori"),
                $it('Articoli', $company->catalogArticles()->where('active', true)->count(), "{$base}/tabelle/catalogo")]],
        ];
    }

    /**
     * Valutazione di entrate e uscite per mese. Competenza: imponibile delle fatture registrate (Registro vendite/acquisti, Note di credito in negativo,
     * registrazioni stornate escluse). Cassa: incassi e pagamenti registrati (Scadenziario). Importi in centesimi come interi.
     *
     * @return array{year: int, accrual: array, cash: array}
     */
    private function analysis(Company $company, int $year): array
    {
        $zero = fn () => array_fill(1, 12, 0);
        $rev = $zero();
        $cost = $zero();
        $vatOut = $zero();
        $vatIn = $zero();
        $regs = VatRegistration::where('company_id', $company->id)->where('year', $year)->where('status', 'registrata')->get(['direction', 'registration_date', 'taxable_total', 'tax_total', 'deductible_total', 'sign']);
        foreach ($regs as $r) {
            $m = (int) $r->registration_date->format('n');
            $t = Decimal::toMinor((string) $r->taxable_total) * ($r->sign ?: 1);
            $v = Decimal::toMinor((string) $r->tax_total) * ($r->sign ?: 1);
            if ($r->direction === InvoiceDirection::Vendita) {
                $rev[$m] += $t;
                $vatOut[$m] += $v;
            } else {
                $cost[$m] += $t;
                $vatIn[$m] += Decimal::toMinor((string) $r->deductible_total) * ($r->sign ?: 1);
            }
        }
        $in = $zero();
        $out = $zero();
        foreach (MoneyMovement::where('company_id', $company->id)->where('status', 'valida')->whereBetween('movement_date', ["{$year}-01-01", "{$year}-12-31"])->get(['kind', 'movement_date', 'amount']) as $mv) {
            $m = (int) $mv->movement_date->format('n');
            $a = Decimal::toMinor((string) $mv->amount);
            $mv->kind === 'incasso' ? $in[$m] += $a : $out[$m] += $a;
        }

        return ['year' => $year,
            'accrual' => ['in' => array_values($rev), 'out' => array_values($cost), 'in_total' => array_sum($rev), 'out_total' => array_sum($cost), 'result' => array_sum($rev) - array_sum($cost), 'vat_out' => array_sum($vatOut), 'vat_in' => array_sum($vatIn)],
            'cash' => ['in' => array_values($in), 'out' => array_values($out), 'in_total' => array_sum($in), 'out_total' => array_sum($out), 'result' => array_sum($in) - array_sum($out)]];
    }

    /**
     * Numeri per il menu (proposta A, 20.11.2026): lavoro aperto per voce di menu, sul gruppo chiuso come somma.
     *
     * @return array<string, int>
     */
    public function navCounts(Company $company): array
    {
        $today = now()->toDateString();
        $year = (int) now()->year;
        $overdue = ['vendita' => 0, 'acquisto' => 0];
        $inst = Installment::where('company_id', $company->id)->whereNotNull('due_date')->where('due_date', '<', $today)
            ->whereHas('invoice', fn ($w) => $w->whereNotIn('status', [InvoiceStatus::Scartata, InvoiceStatus::Archiviata]))->with('invoice')->get();
        foreach ($inst as $i) {
            if (Ledger::installmentState($i)['residual'] > 0 && $i->invoice->direction) {
                $overdue[$i->invoice->direction->value]++;
            }
        }
        $liq = VatSettlement::where('company_id', $company->id)->where('status', 'confermata')->where('review_required', true)->count();
        if ($profile = $company->activeVatProfile($year)) {
            $per = $profile->periodicity->value;
            for ($p = 1; $p <= VatPeriod::max($per) && VatPeriod::end($year, $p, $per) < $today; $p++) {
                $liq += self::needsSettlement($company, $year, $p) ? 1 : 0;
            }
        }
        $xb = collect($this->xb->rows($company, null));

        return [
            'da_registrare' => $company->invoices()->whereIn('status', [InvoiceStatus::Importata, InvoiceStatus::DaVerificare])->count(),
            'da_trasmettere' => $company->invoices()->where('source', 'emessa')->whereNull('transmitted_at')->count(),
            'ricorrenti' => $company->recurringInvoices()->where('active', true)->where('next_date', '<=', $today)->where(fn ($q) => $q->whereNull('end_date')->orWhereColumn('end_date', '>=', 'next_date'))->count(),
            'da_incassare' => $overdue['vendita'],
            'da_pagare' => $overdue['acquisto'],
            'liquidazioni' => $liq,
            'transfrontaliere' => $xb->filter(fn ($r) => $r['state'] === 'integrazione_mancante' || $r['overdue'])->count(),
        ];
    }
}
