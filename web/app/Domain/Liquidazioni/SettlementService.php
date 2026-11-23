<?php

namespace App\Domain\Liquidazioni;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\User;
use App\Models\VatAdjustment;
use App\Models\VatCredit;
use App\Models\VatCreditMovement;
use App\Models\VatRegistration;
use App\Models\VatSettlement;
use App\Support\Decimal;
use App\Support\VatPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Conferma della liquidazione (Bozza → Confermata → Sostituita), rettifiche, marcatura «Liquidazione da riesaminare»
 * e confronto con una nuova versione. Una versione confermata non viene mai modificata.
 */
final class SettlementService
{
    public function __construct(private SettlementCalculator $calc) {}

    public static function isYearEnd(string $periodicity, int $period): bool
    {
        return $period === VatPeriod::max($periodicity);
    }

    /** Periodo successivo (per il riporto); a fine anno periodo 1 dell'anno seguente. */
    public static function next(int $year, int $period, string $periodicity): array
    {
        return $period < VatPeriod::max($periodicity) ? [$year, $period + 1] : [$year + 1, 1];
    }

    public function confirm(Company $company, User $actor, int $year, int $period, ?string $requestId = null): VatSettlement
    {
        return DB::transaction(function () use ($company, $actor, $year, $period, $requestId) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            if ($requestId && ($prev = VatSettlement::where('company_id', $company->id)->where('request_id', $requestId)->first())) {
                $prev->replayed = true;

                return $prev;
            }
            $latest = SettlementCalculator::latest($company, $year, $period);
            if ($latest && ! $latest->review_required) {
                $latest->replayed = true; // secondo clic: stessa liquidazione

                return $latest;
            }
            $this->calc->forget();
            $d = $this->calc->compute($company, $year, $period);
            if (! $d['ok']) {
                RuleViolation::fail(['settlement' => collect($d['issues'])->pluck('message')->join(' ')]);
            }
            if ($d['checks']) {
                RuleViolation::fail(['settlement' => 'Da verificare prima della conferma: '.collect($d['checks'])->pluck('message')->join(' ')]);
            }
            if ($period > 1 && ! SettlementCalculator::latest($company, $year, $period - 1) && $this->hasContent($company, $year, $period - 1)) {
                RuleViolation::fail(['settlement' => 'Conferma prima la liquidazione di '.VatPeriod::label($year, $period - 1, $d['periodicity']).'.']);
            }
            $f = $d['figures'];
            $oldCredits = $latest ? VatCredit::where('settlement_id', $latest->id)->where('status', 'attivo')->get() : collect();
            foreach ($oldCredits as $oc) {
                $bound = Decimal::toMinor((string) $oc->amount) - $oc->residualCents();
                $newAmount = $oc->kind === 'annuale' && self::isYearEnd($d['periodicity'], $period) ? $f['own_credit'] + $this->convertible($company, $d) : $f['own_credit'];
                if ($newAmount < $bound) {
                    RuleViolation::fail(['settlement' => 'La nuova versione riduce il credito a '.self::eur($newAmount).' ma '.self::eur($bound).' sono già destinati. Sblocca o riduci prima le destinazioni indicate nel confronto.']);
                }
            }

            $s = VatSettlement::create([
                'company_id' => $company->id, 'year' => $year, 'period' => $period, 'periodicity' => $d['periodicity'], 'quarterly_type' => $d['quarterly_type'],
                'version' => (int) VatSettlement::where('company_id', $company->id)->where('year', $year)->where('period', $period)->max('version') + 1,
                'status' => 'confermata', 'vat_profile_id' => $d['profile_id'], 'rule_version' => $d['rule_version'], 'data' => $d, 'result' => $d['result'],
                'amount_due' => Decimal::fromMinor($f['due']), 'credit' => Decimal::fromMinor($f['credit']), 'deferred' => Decimal::fromMinor($f['deferred_out']),
                'due_date' => $f['due'] > 0 ? $d['due_date'] : null, 'request_id' => $requestId, 'replaces_id' => $latest?->id,
                'confirmed_by' => $actor->id, 'confirmed_at' => now(),
            ]);
            $latest?->update(['status' => 'sostituita']);

            // Crediti utilizzati: la parte consumata diventa «utilizzata» (stesso movimento), il resto resta presso il credito di origine.
            $left = $f['absorbed'];
            foreach (VatCreditMovement::whereIn('id', array_column($d['credits'], 'movement_id'))->orderBy('id')->lockForUpdate()->get() as $m) {
                $c = Decimal::toMinor((string) $m->amount);
                $use = min($c, $left);
                $left -= $use;
                $upd = ['settlement_id' => $s->id];
                if ($use === $c) {
                    $upd['status'] = 'utilizzata';
                } elseif ($use > 0) {
                    $upd += ['status' => 'utilizzata', 'amount' => Decimal::fromMinor($use), 'reason' => 'Utilizzati '.self::eur($use).' su '.self::eur($c).' nella liquidazione '.$d['period_label'].'; il resto resta disponibile nel credito d’origine.'];
                } else {
                    $upd += ['status' => 'sbloccata', 'reason' => 'Non utilizzato nella liquidazione '.$d['period_label'].' (risultato a credito): importo disponibile nel credito d’origine.'];
                }
                $m->update($upd);
            }

            // Credito maturato nel periodo; a fine anno i crediti residui dell'anno confluiscono nel credito annuale (percorso annuale).
            $yearEnd = self::isYearEnd($d['periodicity'], $period);
            $convert = $yearEnd ? $this->convertible($company, $d) : 0;
            $newCredit = null;
            if ($f['own_credit'] + $convert > 0) {
                $newCredit = VatCredit::create(['company_id' => $company->id, 'kind' => $yearEnd ? 'annuale' : 'periodico', 'origin' => 'liquidazione', 'settlement_id' => $s->id,
                    'year' => $year, 'period' => $period, 'amount' => Decimal::fromMinor($f['own_credit'] + $convert),
                    'description' => ($yearEnd ? "Credito IVA annuale {$year} – " : 'Credito IVA ').'liquidazione '.$d['period_label'], 'rule_version' => $d['rule_version'], 'created_by' => $actor->id]);
                if ($yearEnd) {
                    foreach ($this->convertibleCredits($company, $d) as [$oc, $cents]) {
                        VatCreditMovement::create(['company_id' => $company->id, 'credit_id' => $oc->id, 'destination' => 'annuale', 'amount' => Decimal::fromMinor($cents), 'status' => 'utilizzata',
                            'new_credit_id' => $newCredit->id, 'settlement_id' => $s->id, 'reason' => "Confluito nel credito annuale {$year}", 'created_by' => $actor->id]);
                    }
                }
            }
            // Nuova versione: le destinazioni attive dei vecchi crediti passano al nuovo credito, i vecchi crediti restano come «sostituito».
            foreach ($oldCredits as $oc) {
                if ($newCredit) {
                    VatCreditMovement::where('credit_id', $oc->id)->whereIn('status', ['prenotata', 'utilizzata'])->update(['credit_id' => $newCredit->id]);
                }
                $oc->update(['status' => 'sostituito']);
            }
            if ($latest) {
                VatCreditMovement::where('settlement_id', $latest->id)->where('destination', 'riporto')->update(['settlement_id' => $s->id]);
            }
            Audit::record('settlement.confirmed', $actor, $company, $s, data: ['period' => $d['period_label'], 'version' => $s->version, 'result' => $s->result,
                'due' => $s->amount_due, 'credit' => $s->credit, 'replaces' => $latest?->version]);

            return $s;
        });
    }

    /** Periodo con registrazioni, rettifiche o riporti (i periodi vuoti non devono essere confermati). */
    private function hasContent(Company $company, int $year, int $period): bool
    {
        return VatRegistration::where('company_id', $company->id)->where('year', $year)->where('period', $period)->exists()
            || VatAdjustment::where('company_id', $company->id)->where('year', $year)->where('period', $period)->exists()
            || VatCreditMovement::where('company_id', $company->id)->where('destination', 'riporto')->where('target_year', $year)->where('target_period', $period)->whereIn('status', ['prenotata', 'utilizzata'])->exists();
    }

    /** Crediti residui dell'anno (periodico) che a fine anno passano al credito annuale. */
    private function convertibleCredits(Company $company, array $d): array
    {
        $out = [];
        $ids = array_unique(array_column($d['credits'], 'credit_id'));
        foreach (VatCredit::whereIn('id', $ids)->where('kind', 'periodico')->where('year', $d['year'])->where('status', 'attivo')->get() as $c) {
            $r = $c->residualCents();
            if ($r > 0) {
                $out[] = [$c, $r];
            }
        }

        return $out;
    }

    private function convertible(Company $company, array $d): int
    {
        $sum = 0;
        foreach ($this->convertibleCredits($company, $d) as [, $r]) {
            $sum += $r;
        }

        return max(0, $sum);
    }

    /** Registrazione o rettifica modificata: marcare come «da riesaminare» la liquidazione confermata del periodo. */
    public function touch(Company $company, int $year, int $period, string $why): void
    {
        $s = SettlementCalculator::latest($company, $year, $period);
        if (! $s || $s->review_required) {
            return;
        }
        $this->calc->forget();
        $d = $this->calc->compute($company, $year, $period);
        if (! $d['ok'] || SettlementCalculator::hash($d) !== SettlementCalculator::hash($s->data)) {
            $s->update(['review_required' => true, 'review_reason' => $why]);
        }
    }

    /** Confronto versione confermata ↔ ricalcolo con le conseguenze per periodi successivi, deleghe e pratiche. */
    public function compare(Company $company, VatSettlement $s): array
    {
        $this->calc->forget();
        $d = $this->calc->compute($company, $s->year, $s->period);
        $old = $s->data['figures'];
        $new = $d['ok'] ? $d['figures'] : null;
        $keys = ['debit' => 'IVA a debito', 'deductible' => 'IVA detraibile', 'own' => 'Saldo del periodo', 'tax' => 'Imposta', 'interest' => 'Interessi', 'due' => 'Da versare', 'credit' => 'Credito'];
        $rows = [];
        foreach ($keys as $k => $label) {
            $rows[] = ['key' => $k, 'label' => $label, 'old' => Decimal::fromMinor($old[$k]), 'new' => $new ? Decimal::fromMinor($new[$k]) : null, 'diff' => $new ? Decimal::fromMinor($new[$k] - $old[$k]) : null];
        }
        $effects = [];
        $credits = VatCredit::where('settlement_id', $s->id)->get();
        foreach ($credits as $c) {
            foreach ($c->movements()->with('f24', 'claim')->whereIn('status', ['prenotata', 'utilizzata'])->get() as $m) {
                $effects[] = match ($m->destination) {
                    'riporto' => ['type' => 'periodo', 'text' => 'Riporto di '.self::eur(Decimal::toMinor((string) $m->amount)).' alla liquidazione '.VatPeriod::label($m->target_year, $m->target_period, $s->periodicity).' ('.$m->status.')',
                        'link' => ['year' => $m->target_year, 'period' => $m->target_period]],
                    'compensazione' => ['type' => 'f24', 'text' => 'Compensazione in F24 di '.self::eur(Decimal::toMinor((string) $m->amount)).($m->f24 ? ' · delega n. '.$m->f24->id.' ('.F24Service::STATUS[$m->f24->status].')' : ' · prenotata'), 'f24_id' => $m->f24_id],
                    'rimborso', 'tr' => ['type' => 'rimborso', 'text' => 'Rimborso di '.self::eur(Decimal::toMinor((string) $m->amount)).($m->claim ? ' · pratica n. '.$m->claim->id.' ('.ClaimService::STATUS[$m->claim->status].')' : ''), 'claim_id' => $m->claim_id],
                    default => ['type' => 'altro', 'text' => ucfirst($m->destination).' '.self::eur(Decimal::toMinor((string) $m->amount))],
                };
            }
        }
        foreach ($s->f24s()->get() as $f) {
            $effects[] = ['type' => 'f24', 'text' => 'Delega F24 n. '.$f->id.' di '.self::eur(Decimal::toMinor((string) $f->balance)).' ('.F24Service::STATUS[$f->status].'): resta agli atti; un maggior debito si regolarizza con ravvedimento (verifica professionale).', 'f24_id' => $f->id];
        }
        $later = VatSettlement::where('company_id', $company->id)->where('status', 'confermata')
            ->where(fn ($q) => $q->where('year', '>', $s->year)->orWhere(fn ($w) => $w->where('year', $s->year)->where('period', '>', $s->period)))->orderBy('year')->orderBy('period')->get();
        foreach ($later as $l) {
            $effects[] = ['type' => 'periodo', 'text' => 'Liquidazione successiva confermata: '.$l->data['period_label'].' (riporti e debiti rinviati da ricontrollare)', 'link' => ['year' => $l->year, 'period' => $l->period]];
        }
        $creditDiff = $new ? $new['credit'] - $old['credit'] : 0;

        return ['rows' => $rows, 'effects' => $effects, 'credit_diff' => Decimal::fromMinor($creditDiff), 'due_diff' => $new ? Decimal::fromMinor($new['due'] - $old['due']) : null,
            'new_checks' => $d['checks'] ?? [], 'new_ok' => $d['ok'], 'reason' => $s->review_reason];
    }

    public function addAdjustment(Company $company, User $actor, int $year, int $period, array $a): VatAdjustment
    {
        $p = $company->activeVatProfile($year);
        $e = [];
        if (! $p || ! $p->periodicity) {
            $e['kind'] = "Conferma prima il regime IVA {$year}.";
        }
        $cents = Decimal::toMinor(str_replace(',', '.', (string) $a['amount']));
        if (! in_array($a['kind'], ['debito', 'detrazione', 'acconto'], true)) {
            $e['kind'] = 'Tipo non valido.';
        } elseif ($a['kind'] === 'acconto' && $p && ! self::isYearEnd($p->periodicity->value, $period)) {
            $e['kind'] = 'L’acconto IVA si riconosce nella liquidazione di dicembre (mensili) o del 4° trimestre.';
        }
        if ($cents === 0 || ($a['kind'] === 'acconto' && $cents < 0)) {
            $e['amount'] = 'Importo non valido.';
        }
        if (trim((string) ($a['causale'] ?? '')) === '') {
            $e['causale'] = 'Indica la causale.';
        }
        if ($e) {
            RuleViolation::fail($e);
        }

        return DB::transaction(function () use ($company, $actor, $year, $period, $a, $cents) {
            $adj = VatAdjustment::create(['company_id' => $company->id, 'year' => $year, 'period' => $period, 'kind' => $a['kind'], 'amount' => Decimal::fromMinor($cents),
                'causale' => trim($a['causale']), 'reference' => ($a['reference'] ?? '') ?: null, 'created_by' => $actor->id]);
            Audit::record('settlement.adjustment_added', $actor, $company, $adj, data: ['year' => $year, 'period' => $period, 'kind' => $adj->kind, 'amount' => $adj->amount, 'causale' => $adj->causale, 'reference' => $adj->reference]);
            $this->touch($company, $year, $period, 'Rettifica manuale aggiunta: '.$adj->causale);

            return $adj;
        });
    }

    public function deleteAdjustment(Company $company, User $actor, VatAdjustment $adj): void
    {
        DB::transaction(function () use ($company, $actor, $adj) {
            Audit::record('settlement.adjustment_deleted', $actor, $company, $adj, data: ['year' => $adj->year, 'period' => $adj->period, 'kind' => $adj->kind, 'amount' => $adj->amount, 'causale' => $adj->causale]);
            $adj->delete();
            $this->touch($company, $adj->year, $adj->period, 'Rettifica manuale eliminata: '.$adj->causale);
        });
    }

    public static function eur(int $cents): string
    {
        $s = Decimal::fromMinor(abs($cents));
        [$i, $dec] = explode('.', $s);

        return ($cents < 0 ? '−' : '').number_format((int) $i, 0, ',', '.').','.$dec.' €';
    }
}
