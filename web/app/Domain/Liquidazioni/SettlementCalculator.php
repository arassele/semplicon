<?php

namespace App\Domain\Liquidazioni;

use App\Domain\VatRegisterService;
use App\Models\Company;
use App\Models\VatAdjustment;
use App\Models\VatCreditMovement;
use App\Models\VatRegistration;
use App\Models\VatSettlement;
use App\Support\Decimal;
use App\Support\VatPeriod;

/**
 * Prospetto della liquidazione IVA di un periodo dalle registrazioni (periodo della registrazione), rettifiche,
 * crediti e rinviati. Importi in centesimi. Regole: docs/FISCAL_RULES.md, R-LIQ-*.
 *
 * imposta = (IVA a debito + rettifiche) − (IVA detraibile dopo pro rata + rettifiche) + debiti rinviati − crediti utilizzati − acconto
 */
final class SettlementCalculator
{
    /** @var array<string, array> */
    private array $cache = [];

    /** Cosa manca per calcolare il periodo (campo e avviso). */
    public function setupIssues(Company $company, int $year): array
    {
        $p = $company->activeVatProfile($year);
        $issues = [];
        if (! $p) {
            return [['field' => 'regime', 'message' => "Conferma il regime IVA {$year} (Regime IVA › {$year})."]];
        }
        if (! $p->periodicity) {
            $issues[] = ['field' => 'periodicity', 'message' => "Indica la periodicità delle liquidazioni nel regime IVA {$year}."];
        } elseif ($p->periodicity->value === 'trimestrale' && ! $p->quarterly_type) {
            $issues[] = ['field' => 'quarterly_type', 'message' => "Indica nel regime IVA {$year} il tipo di trimestrale (per opzione o speciale)."];
        }
        if (! Rules::for($year)) {
            $issues[] = ['field' => 'rules', 'message' => "Regole di liquidazione per il {$year} non ancora disponibili in Semplicon: calcolo da verificare con il professionista."];
        }

        return $issues;
    }

    public function compute(Company $company, int $year, int $period): array
    {
        $key = "{$company->id}:{$year}:{$period}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $profile = $company->activeVatProfile($year);
        $issues = $this->setupIssues($company, $year);
        if ($issues) {
            return ['ok' => false, 'year' => $year, 'period' => $period, 'issues' => $issues];
        }
        $periodicity = $profile->periodicity->value;
        $qtype = $profile->quarterly_type;
        $rules = Rules::for($year);
        $checks = [];   // Da verificare (bloccante)
        $notes = [];    // Avvisi

        if ($profile->forfettario_iva) {
            $checks[] = ['message' => "Soggetto forfettario con IVA nel {$year}: la liquidazione periodica non è prevista da una regola implementata. Verifica con il professionista prima della conferma.", 'documents' => []];
        }

        $regs = VatRegistration::where('company_id', $company->id)->where('year', $year)->where('period', $period)
            ->with(['invoice.vatSummaries', 'lines'])->orderBy('direction')->orderBy('protocol')->get();
        $sum = ['vendite' => 0, 'integrazioni' => 0, 'acquisti' => 0, 'split' => 0];
        $detail = ['vendite' => [], 'integrazioni' => [], 'acquisti' => [], 'split' => [], 'rettifiche' => []];
        $wrongPeriodicity = [];
        $deferredVat = [];
        foreach ($regs as $r) {
            if ($r->periodicity !== $periodicity) {
                $wrongPeriodicity[] = $this->doc($r, 0);
            }
            $inv = $r->invoice;
            $integrationDoc = in_array($inv->doc_type, VatRegisterService::INTEGRATION_TYPES, true);
            $integration = $integrationDoc || $r->lines->contains('integrated', true);
            $tax = 0;
            $ded = 0;
            $split = 0;
            foreach ($r->lines as $l) {
                $s = $inv->vatSummaries->values()[$l->summary_index ?? 0] ?? null;
                $esig = $s?->esigibilita;
                $t = $r->sign * Decimal::toMinor((string) $l->tax);
                if ($esig === 'D') {
                    $deferredVat[$r->id] = $this->doc($r, $t);
                }
                if ($r->direction->value === 'vendita') {
                    if ($esig === 'S') {
                        $split += $t;  // split payment: IVA versata dal cessionario (art. 17-ter)
                    } else {
                        $tax += $t;
                    }
                } else {
                    $ded += $r->sign * Decimal::toMinor((string) $l->deductible_tax);
                    if ($integrationDoc || $l->integrated) {
                        $tax += $t;
                    } elseif (str_starts_with((string) $l->natura, 'N6') && $t === 0) {
                        $notes['n6_'.$r->id] = 'Acquisto '.$inv->number.' in reverse charge (natura '.$l->natura.'): l’imposta entra nella liquidazione con il documento di integrazione (TD16–TD19).';
                    }
                }
            }
            $kind = $r->direction->value === 'vendita' ? 'vendite' : 'acquisti';
            if ($kind === 'vendite' && $tax !== 0) {
                $sum['vendite'] += $tax;
                $detail['vendite'][] = $this->doc($r, $tax);
            }
            if ($split !== 0) {
                $sum['split'] += $split;
                $detail['split'][] = $this->doc($r, $split);
            }
            if ($kind === 'acquisti') {
                if ($integration && $tax !== 0) {
                    $sum['integrazioni'] += $tax;
                    $detail['integrazioni'][] = $this->doc($r, $tax);
                }
                $sum['acquisti'] += $ded;
                $detail['acquisti'][] = $this->doc($r, $ded, $tax);
            }
            if ($inv->doc_type === 'TD04' || $r->status === 'storno') {
                $detail['rettifiche'][] = [...$this->doc($r, $kind === 'vendite' ? $tax : $ded), 'type' => $r->status === 'storno' ? 'Storno' : 'Nota di credito', 'register' => $kind];
            }
        }
        if ($wrongPeriodicity) {
            $checks[] = ['message' => 'Registrazioni con periodicità diversa dal regime confermato: verifica il periodo IVA attribuito.', 'documents' => $wrongPeriodicity];
        }
        if ($deferredVat) {
            $checks[] = ['message' => 'IVA ad esigibilità differita (EsigibilitaIVA “D”, IVA per cassa): regola non implementata. Verifica il periodo di esigibilità o detrazione prima della conferma.', 'documents' => array_values($deferredVat)];
        }

        // Pro rata: una sola volta sull'IVA detraibile dopo l'indetraibilità specifica.
        $pr = Decimal::toMinor((string) $profile->pro_rata);
        $dedNet = $pr >= 10000 ? $sum['acquisti'] : self::roundDiv($sum['acquisti'] * $pr, 10000);

        $adj = VatAdjustment::where('company_id', $company->id)->where('year', $year)->where('period', $period)->with('creator')->orderBy('id')->get();
        $adjSum = ['debito' => 0, 'detrazione' => 0, 'acconto' => 0];
        $adjList = [];
        foreach ($adj as $a) {
            $c = Decimal::toMinor((string) $a->amount);
            $adjSum[$a->kind] += $c;
            $adjList[] = ['id' => $a->id, 'kind' => $a->kind, 'amount' => Decimal::fromMinor($c), 'causale' => $a->causale, 'reference' => $a->reference, 'author' => $a->creator?->name, 'date' => $a->created_at->toDateString()];
        }

        // Crediti utilizzati: riporti in questo periodo (prenotata/utilizzata), distinti per provenienza.
        $mov = VatCreditMovement::where('company_id', $company->id)->where('destination', 'riporto')->where('target_year', $year)->where('target_period', $period)
            ->whereIn('status', ['prenotata', 'utilizzata'])->with('credit.settlement')->orderBy('id')->get();
        $credits = ['precedente' => 0, 'annuale' => 0];
        $creditList = [];
        foreach ($mov as $m) {
            $c = Decimal::toMinor((string) $m->amount);
            $type = $m->credit->kind === 'annuale' ? 'annuale' : 'precedente';
            $credits[$type] += $c;
            $creditList[] = ['movement_id' => $m->id, 'credit_id' => $m->credit_id, 'type' => $type, 'amount' => Decimal::fromMinor($c), 'status' => $m->status,
                'origin' => $m->credit->description, 'credit_residual' => Decimal::fromMinor($m->credit->residualCents())];
        }
        $creditsUsed = $credits['precedente'] + $credits['annuale'];

        // Debiti di modesto importo rinviati dal periodo precedente dello stesso anno.
        $deferredIn = 0;
        $deferredFrom = null;
        if ($period > 1) {
            $prev = self::latest($company, $year, $period - 1);
            $deferredIn = $prev ? Decimal::toMinor((string) $prev->deferred) : ($this->compute($company, $year, $period - 1)['figures']['deferred_out'] ?? 0);
            if ($deferredIn > 0) {
                $deferredFrom = VatPeriod::label($year, $period - 1, $periodicity);
            }
        }

        $debit = $sum['vendite'] + $sum['integrazioni'] + $adjSum['debito'];
        $deductible = $dedNet + $adjSum['detrazione'];
        $own = $debit - $deductible;                            // saldo del periodo
        $base = $own + $deferredIn - $adjSum['acconto'];        // prima dei crediti
        $tax = $base - $creditsUsed;
        $interest = 0;
        $deferredOut = 0;
        $due = 0;
        $credit = 0;
        $small = Rules::cents($rules, 'small_debt_limit');
        $nominal = Rules::nominalDue($year, $period, $periodicity, $qtype);
        $next = $period < VatPeriod::max($periodicity) ? Rules::nominalDue($year, $period + 1, $periodicity, $qtype) : '9999-12-31';
        $limit = $year.'-'.$rules['small_debt_deadline'];
        $deferralAllowed = $nominal < $limit && $next <= $limit;
        $optionInterest = $periodicity === 'trimestrale' && $qtype === 'opzione';
        if ($tax > 0) {
            if ($tax <= $small && $deferralAllowed) {
                $deferredOut = $tax;
                $notes['small'] = 'Debito non superiore a '.str_replace('.', ',', $rules['small_debt_limit']).' €: rinviato al periodo successivo (versamento entro il 16 dicembre '.$year.').';
            } else {
                if ($tax <= $small) {
                    $notes['small'] = 'Debito non superiore a '.str_replace('.', ',', $rules['small_debt_limit']).' €, ma il rinvio non è consentito: il periodo successivo scade dopo il 16 dicembre '.$year.'. Da versare ora.';
                }
                $interest = $optionInterest ? self::roundDiv($tax * Decimal::toMinor($rules['quarterly_interest_pct']), 10000) : 0;
                $due = $tax + $interest;
            }
        } elseif ($tax < 0) {
            $credit = -$tax;
        }
        $absorbed = min($creditsUsed, max(0, $base));
        $ownCredit = max(0, -$base);
        $result = $tax > 0 ? 'debito' : ($tax < 0 ? 'credito' : 'zero');
        if ($periodicity === 'trimestrale' && $period === 4 && $qtype === 'opzione') {
            $notes['annual'] = 'Trimestrale per opzione: il 4° trimestre segue il percorso del saldo annuale (codice 6099, interessi 1%, scadenza 16 marzo '.($year + 1).'). Verifica con la dichiarazione IVA annuale.';
        }
        $code = Rules::code($year, $period, $periodicity, $qtype);

        $data = [
            'ok' => true, 'year' => $year, 'period' => $period, 'periodicity' => $periodicity, 'quarterly_type' => $qtype,
            'period_label' => VatPeriod::label($year, $period, $periodicity), 'profile_id' => $profile->id, 'profile_label' => $profile->regimeLabel(),
            'pro_rata' => (string) $profile->pro_rata, 'rule_version' => $rules['version'],
            'figures' => [
                'sales' => $sum['vendite'], 'integrations' => $sum['integrazioni'], 'adj_debit' => $adjSum['debito'], 'debit' => $debit, 'split' => $sum['split'],
                'deductible_gross' => $sum['acquisti'], 'pro_rata_reduction' => $sum['acquisti'] - $dedNet, 'adj_deductible' => $adjSum['detrazione'], 'deductible' => $deductible,
                'own' => $own, 'deferred_in' => $deferredIn, 'credit_previous' => $credits['precedente'], 'credit_annual' => $credits['annuale'], 'acconto' => $adjSum['acconto'],
                'tax' => $tax, 'interest' => $interest, 'due' => $due, 'deferred_out' => $deferredOut, 'credit' => $credit, 'own_credit' => $ownCredit, 'absorbed' => $absorbed,
            ],
            'result' => $result, 'deferral_allowed' => $deferralAllowed, 'deferred_from' => $deferredFrom,
            'due_date' => Rules::dueDate($year, $period, $periodicity, $qtype), 'code' => $code,
            'detail' => $detail, 'adjustments' => $adjList, 'credits' => $creditList,
            'registration_ids' => $regs->pluck('id')->all(), 'checks' => $checks, 'notes' => array_values($notes), 'issues' => [],
        ];
        $data['hash'] = self::hash($data);

        return $this->cache[$key] = $data;
    }

    /** Elemento di confronto per «da riesaminare»: registrazioni, rettifiche, profilo e importi propri (senza utilizzo dei crediti). */
    public static function hash(array $d): string
    {
        $f = $d['figures'];

        return hash('sha256', json_encode([$f['debit'], $f['deductible'], $f['own'], $f['deferred_in'], $f['acconto'], $d['registration_ids'], array_column($d['adjustments'], 'id'), $d['profile_id']]));
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    public static function latest(Company $company, int $year, int $period): ?VatSettlement
    {
        return VatSettlement::where('company_id', $company->id)->where('year', $year)->where('period', $period)->where('status', 'confermata')->orderByDesc('version')->first();
    }

    private function doc(VatRegistration $r, int $amount, ?int $extra = null): array
    {
        $inv = $r->invoice;

        return ['registration_id' => $r->id, 'invoice_id' => $inv->id, 'protocol' => $r->protocol.'/'.$r->year, 'direction' => $r->direction->value, 'status' => $r->status,
            'doc_type' => $inv->doc_type, 'number' => $inv->number, 'doc_date' => $inv->doc_date->toDateString(),
            'counterparty' => $r->direction->value === 'vendita' ? $inv->customer_name : $inv->supplier_name,
            'amount' => Decimal::fromMinor($amount), 'tax' => $extra === null ? null : Decimal::fromMinor($extra)];
    }

    /** Divisione intera con arrotondamento del mezzo centesimo in valore assoluto. */
    public static function roundDiv(int $num, int $den): int
    {
        $r = intdiv(abs($num) * 2 + $den, 2 * $den);

        return $num < 0 ? -$r : $r;
    }
}
