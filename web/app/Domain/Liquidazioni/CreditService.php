<?php

namespace App\Domain\Liquidazioni;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\User;
use App\Models\VatClaim;
use App\Models\VatCredit;
use App\Models\VatCreditMovement;
use App\Models\VatSettlement;
use App\Support\Decimal;
use App\Support\VatPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Registro crediti IVA e destinazione del credito: riporto IVA su IVA, compensazione in F24, rimborso, IVA TR.
 * Ogni quota ha esattamente una destinazione attiva (movimento prenotata/utilizzata); verifiche con blocco del credito.
 */
final class CreditService
{
    public const KIND = ['periodico' => 'Credito periodico', 'trimestrale' => 'Credito trimestrale (IVA TR)', 'annuale' => 'Credito annuale'];

    public const DESTINATION = ['riporto' => 'Riporto alla liquidazione successiva', 'compensazione' => 'Compensazione in F24', 'rimborso' => 'Rimborso', 'tr' => 'Trasferito in IVA TR', 'annuale' => 'Confluito nel credito annuale'];

    public const MOVEMENT_STATUS = ['prenotata' => 'Prenotata', 'utilizzata' => 'Utilizzata', 'sbloccata' => 'Sbloccata'];

    /**
     * Cosa è possibile con una quota (destinazione, importo, data di utilizzo).
     *
     * @return array{ok: bool, reasons: list<string>, first_date: ?string, max: int}
     */
    public function availability(VatCredit $c, string $dest, int $amount, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $rules = Rules::for((int) substr($date, 0, 4)) ?? Rules::for($c->year + 1) ?? Rules::for($c->year);
        $residual = $c->residualCents();
        $reasons = [];
        $first = null;
        $claim = $c->kind === 'trimestrale' ? VatClaim::where('credit_id', $c->id)->latest('id')->first() : null;
        if ($c->status !== 'attivo') {
            $reasons[] = 'Credito sostituito da una nuova versione della liquidazione.';
        }
        if ($dest === 'riporto') {
            if ($c->kind === 'trimestrale' && $claim?->status !== 'respinto') {
                $reasons[] = 'Il credito chiesto con IVA TR si utilizza in compensazione o a rimborso; si riporta solo dopo il rigetto dell’istanza.';
            }
        } elseif ($dest === 'compensazione') {
            if ($c->kind === 'periodico') {
                $reasons[] = 'Il credito di un singolo periodo confluisce nel riporto: la compensazione in F24 spetta al credito annuale o al credito trimestrale chiesto con IVA TR.';
            } elseif ($rules) {
                $limit = Rules::cents($rules, 'compensation_free_limit');
                $used = $this->compensated($c, $c->kind);
                $filed = $c->kind === 'annuale' ? $c->filed_at?->toDateString() : ($claim && in_array($claim->status, ['richiesto', 'erogato'], true) ? $claim->filed_at?->toDateString() : null);
                if ($c->kind === 'annuale') {
                    $first = ($c->year + 1).'-01-01';
                    if ($used + $amount > $limit) {
                        if (! $filed) {
                            $reasons[] = 'Oltre '.SettlementService::eur($limit).' annui serve la presentazione della dichiarazione IVA '.$c->year.' (data di presentazione da indicare nel registro crediti).';
                        } else {
                            $first = max($first, date('Y-m-d', strtotime($filed.' +'.$rules['compensation_days_after_filing'].' days')));
                        }
                    }
                } else {
                    if (! $filed) {
                        $reasons[] = 'Il credito trimestrale si compensa dopo la presentazione del modello IVA TR (stato “Richiesto”).';
                    } else {
                        $first = $filed;
                        if ($used + $amount > $limit) {
                            $first = date('Y-m-d', strtotime($filed.' +'.$rules['compensation_days_after_filing'].' days'));
                        }
                    }
                }
                if ($used + $amount > Rules::cents($rules, 'visto_limit') && ! $c->visto && ($c->visto_exempt_limit === null || $used + $amount > Decimal::toMinor((string) $c->visto_exempt_limit))) {
                    $reasons[] = 'Compensazioni oltre '.SettlementService::eur(Rules::cents($rules, 'visto_limit')).': serve il visto di conformità sulla '.($c->kind === 'annuale' ? 'dichiarazione' : 'istanza TR').' (o un esonero valido).';
                }
                $yearUsed = VatCreditMovement::where('company_id', $c->company_id)->where('destination', 'compensazione')->whereIn('status', ['prenotata', 'utilizzata'])
                    ->whereYear('created_at', substr($date, 0, 4))->get()->sum(fn ($m) => Decimal::toMinor((string) $m->amount));
                if ($yearUsed + $amount > Rules::cents($rules, 'compensation_yearly_cap')) {
                    $reasons[] = 'Supera il limite annuo delle compensazioni ('.SettlementService::eur(Rules::cents($rules, 'compensation_yearly_cap')).').';
                }
                if ($first && $date < $first) {
                    $reasons[] = 'Utilizzabile in F24 dal '.self::it($first).'.';
                }
            }
        } elseif ($dest === 'rimborso') {
            if ($c->kind === 'periodico') {
                $reasons[] = 'Il credito di un singolo periodo non è rimborsabile: per il rimborso infrannuale si valuta il trimestre solare completo (IVA TR) nella liquidazione di fine trimestre.';
            } elseif ($c->kind === 'trimestrale') {
                $reasons[] = 'Il rimborso del credito trimestrale si indica nella pratica IVA TR.';
            }
        }
        if ($amount > $residual) {
            $reasons[] = 'Supera il residuo disponibile ('.SettlementService::eur($residual).').';
        }

        return ['ok' => ! $reasons, 'reasons' => $reasons, 'first_date' => $first, 'max' => $residual];
    }

    /** Importi già compensati dello stesso anno e tipo di credito (per la soglia di 5.000 €). */
    private function compensated(VatCredit $c, string $kind): int
    {
        return VatCreditMovement::where('vat_credit_movements.company_id', $c->company_id)->where('destination', 'compensazione')->whereIn('vat_credit_movements.status', ['prenotata', 'utilizzata'])
            ->whereHas('credit', fn ($q) => $q->where('kind', $kind)->where('year', $c->year))->get()->sum(fn ($m) => Decimal::toMinor((string) $m->amount));
    }

    /** Componenti del credito dietro il risultato «a credito» di una liquidazione: credito proprio e residui dei riporti. */
    public function settlementCredits(Company $company, VatSettlement $s): array
    {
        $own = VatCredit::where('settlement_id', $s->id)->where('status', 'attivo')->get();
        $originIds = VatCreditMovement::where('settlement_id', $s->id)->where('destination', 'riporto')->pluck('credit_id');
        $origins = VatCredit::whereIn('id', $originIds)->where('status', 'attivo')->whereNotIn('id', $own->pluck('id'))->get()->filter(fn ($c) => $c->residualCents() > 0);

        return [$own->values(), $origins->values()];
    }

    /**
     * Destinazione del credito: per ogni credito importi per riporto, compensazione, rimborso. Senza indicazione vale il riporto.
     *
     * @param  list<array{credit_id: int, riporto?: string, compensazione?: string, rimborso?: string}>  $rows
     * @param  array{target_year?: int, target_period?: int, presupposto?: ?string, documentation?: ?string}  $opt
     */
    public function allocate(Company $company, User $actor, array $rows, array $opt = []): array
    {
        return DB::transaction(function () use ($company, $actor, $rows, $opt) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $made = [];
            $errors = [];
            foreach ($rows as $k => $r) {
                $c = VatCredit::where('company_id', $company->id)->lockForUpdate()->find((int) $r['credit_id']);
                if (! $c) {
                    $errors["rows.{$k}"] = 'Credito non trovato.';

                    continue;
                }
                $amounts = [];
                foreach (['riporto', 'compensazione', 'rimborso'] as $dest) {
                    $amounts[$dest] = Decimal::toMinor(str_replace(',', '.', (string) (($r[$dest] ?? '') ?: '0')));
                    if ($amounts[$dest] < 0) {
                        $errors["rows.{$k}.{$dest}"] = 'Importo non valido.';
                    }
                }
                if (array_sum($amounts) > $c->residualCents()) {
                    $errors["rows.{$k}"] = $c->description.': la ripartizione ('.SettlementService::eur(array_sum($amounts)).') supera il residuo ('.SettlementService::eur($c->residualCents()).').';

                    continue;
                }
                foreach ($amounts as $dest => $cents) {
                    if ($cents === 0) {
                        continue;
                    }
                    $av = $this->availability($c, $dest, $cents);
                    // La compensazione può essere prenotata anche se presentazione/decorrenza mancano ancora (verifica all'utilizzo nell'F24).
                    $blocking = $dest === 'compensazione' ? array_filter($av['reasons'], fn ($x) => str_contains($x, 'singolo periodo') || str_contains($x, 'residuo') || str_contains($x, 'sostituito')) : $av['reasons'];
                    if ($blocking) {
                        $errors["rows.{$k}.{$dest}"] = implode(' ', $blocking);
                    }
                }
                if ($amounts['rimborso'] > 0 && empty($opt['presupposto'])) {
                    $errors["rows.{$k}.rimborso"] = 'Indica il presupposto del rimborso.';
                }
                if ($errors) {
                    continue;
                }
                [$ty, $tp] = isset($opt['target_year'], $opt['target_period']) ? [(int) $opt['target_year'], (int) $opt['target_period']] : $this->nextFor($company, $c);
                if ($amounts['riporto'] > 0) {
                    if ($ty * 100 + $tp <= $c->year * 100 + (int) $c->period && $c->origin === 'liquidazione') {
                        $errors["rows.{$k}.riporto"] = 'Il riporto va a una liquidazione successiva.';

                        continue;
                    }
                    if (SettlementCalculator::latest($company, $ty, $tp)) {
                        $errors["rows.{$k}.riporto"] = 'La liquidazione di destinazione è già confermata.';

                        continue;
                    }
                    $made[] = VatCreditMovement::create(['company_id' => $company->id, 'credit_id' => $c->id, 'destination' => 'riporto', 'amount' => Decimal::fromMinor($amounts['riporto']),
                        'status' => 'prenotata', 'target_year' => $ty, 'target_period' => $tp, 'created_by' => $actor->id]);
                }
                if ($amounts['compensazione'] > 0) {
                    $made[] = VatCreditMovement::create(['company_id' => $company->id, 'credit_id' => $c->id, 'destination' => 'compensazione', 'amount' => Decimal::fromMinor($amounts['compensazione']),
                        'status' => 'prenotata', 'created_by' => $actor->id]);
                }
                if ($amounts['rimborso'] > 0) {
                    $claim = VatClaim::create(['company_id' => $company->id, 'kind' => 'annuale', 'year' => $c->year, 'credit_id' => $c->id, 'refund_amount' => Decimal::fromMinor($amounts['rimborso']),
                        'presupposto' => $opt['presupposto'], 'documentation' => ($opt['documentation'] ?? '') ?: null, 'status' => 'bozza', 'created_by' => $actor->id]);
                    $made[] = VatCreditMovement::create(['company_id' => $company->id, 'credit_id' => $c->id, 'destination' => 'rimborso', 'amount' => Decimal::fromMinor($amounts['rimborso']),
                        'status' => 'prenotata', 'claim_id' => $claim->id, 'created_by' => $actor->id]);
                }
            }
            if ($errors) {
                RuleViolation::fail($errors);
            }
            Audit::record('credit.allocated', $actor, $company, data: ['movements' => array_map(fn ($m) => ['id' => $m->id, 'credit' => $m->credit_id, 'destination' => $m->destination, 'amount' => $m->amount], $made)]);

            return $made;
        });
    }

    /** Destinazione del riporto: periodo successivo alla formazione, per i crediti annuali dalla dichiarazione il primo periodo aperto. */
    public function nextFor(Company $company, VatCredit $c): array
    {
        if ($c->settlement_id && $c->settlement) {
            return SettlementService::next($c->settlement->year, $c->settlement->period, $c->settlement->periodicity);
        }
        $year = $c->year + 1;
        $p = $company->activeVatProfile($year);
        $max = $p?->periodicity ? VatPeriod::max($p->periodicity->value) : 12;
        for ($i = 1; $i <= $max; $i++) {
            if (! SettlementCalculator::latest($company, $year, $i)) {
                return [$year, $i];
            }
        }

        return [$year + 1, 1];
    }

    /**
     * Verifica infrannuale (IVA TR): credito del trimestre solare completo senza riporti precedenti, soglia strettamente superiore.
     *
     * @return array{quarter: int, eligible: bool, matured: int, admissible: int, threshold: int, reasons: list<string>, deadline: ?string, credits: list<array{0: VatCredit, 1: int}>}
     */
    public function trCheck(Company $company, int $year, int $quarter): array
    {
        $rules = Rules::for($year);
        $p = $company->activeVatProfile($year);
        $reasons = [];
        $out = ['quarter' => $quarter, 'eligible' => false, 'matured' => 0, 'admissible' => 0, 'threshold' => $rules ? Rules::cents($rules, 'tr_threshold') : 0, 'reasons' => [], 'deadline' => Rules::trDeadline($year, $quarter), 'credits' => []];
        if (! $rules || ! $p?->periodicity) {
            $out['reasons'] = ['Regole o regime IVA dell’anno mancanti.'];

            return $out;
        }
        if ($quarter > 3) {
            $out['reasons'] = ['Per il quarto trimestre si segue il percorso annuale (dichiarazione IVA).'];

            return $out;
        }
        $periods = $p->periodicity->value === 'mensile' ? [$quarter * 3 - 2, $quarter * 3 - 1, $quarter * 3] : [$quarter];
        $own = 0;
        foreach ($periods as $per) {
            $s = SettlementCalculator::latest($company, $year, $per);
            if (! $s) {
                $reasons[] = 'Liquidazione di '.VatPeriod::label($year, $per, $p->periodicity->value).' non confermata: serve il trimestre completo.';

                continue;
            }
            $own += $s->data['figures']['own'] - $s->data['figures']['acconto'];
            foreach (VatCredit::where('settlement_id', $s->id)->where('status', 'attivo')->where('kind', 'periodico')->get() as $c) {
                $r = $c->residualCents();
                if ($r > 0) {
                    $out['credits'][] = [$c, $r];
                }
            }
        }
        $matured = max(0, -$own);
        $out['matured'] = $matured;
        if ($matured <= $out['threshold']) {
            $reasons[] = 'Credito maturato nel trimestre '.SettlementService::eur($matured).': deve essere strettamente superiore a '.SettlementService::eur($out['threshold']).' (esclusi i riporti dei periodi precedenti).';
        }
        $avail = array_sum(array_map(fn ($x) => $x[1], $out['credits']));
        $out['admissible'] = min($matured, $avail);
        if ($out['deadline'] && now()->toDateString() > $out['deadline']) {
            $reasons[] = 'Termine di presentazione del modello TR scaduto ('.self::it($out['deadline']).').';
        }
        $out['eligible'] = ! $reasons && $out['admissible'] > 0;
        $out['reasons'] = $reasons;

        return $out;
    }

    /** Pratica IVA TR: quote dai crediti del trimestre in un credito «trimestrale», rimborso e/o compensazione. */
    public function requestTr(Company $company, User $actor, int $year, int $quarter, array $d): VatClaim
    {
        return DB::transaction(function () use ($company, $actor, $year, $quarter, $d) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $chk = $this->trCheck($company, $year, $quarter);
            $refund = Decimal::toMinor(str_replace(',', '.', (string) (($d['refund'] ?? '') ?: '0')));
            $comp = Decimal::toMinor(str_replace(',', '.', (string) (($d['compensation'] ?? '') ?: '0')));
            $e = [];
            if (! $chk['eligible']) {
                $e['tr'] = implode(' ', $chk['reasons']) ?: 'IVA TR non disponibile.';
            } elseif ($refund + $comp <= 0 || $refund < 0 || $comp < 0 || $refund + $comp > $chk['admissible']) {
                $e['tr'] = 'Importo tra 0,01 e '.SettlementService::eur($chk['admissible']).' (quota ammissibile del trimestre).';
            }
            if (empty($d['presupposto']) || ! array_key_exists($d['presupposto'], Rules::for($year)['tr_presupposti'] ?? [])) {
                $e['presupposto'] = 'Indica il presupposto (art. 38-bis c. 2).';
            }
            if ($e) {
                RuleViolation::fail($e);
            }
            $total = $refund + $comp;
            $tr = VatCredit::create(['company_id' => $company->id, 'kind' => 'trimestrale', 'origin' => 'tr', 'year' => $year, 'period' => $quarter, 'amount' => Decimal::fromMinor($total),
                'description' => "Credito IVA TR {$quarter}° trimestre {$year}", 'visto' => (bool) ($d['visto'] ?? false), 'rule_version' => Rules::for($year)['version'], 'created_by' => $actor->id]);
            $left = $total;
            foreach ($chk['credits'] as [$c, $r]) {
                if ($left <= 0) {
                    break;
                }
                $use = min($r, $left);
                $left -= $use;
                VatCreditMovement::create(['company_id' => $company->id, 'credit_id' => $c->id, 'destination' => 'tr', 'amount' => Decimal::fromMinor($use), 'status' => 'utilizzata',
                    'new_credit_id' => $tr->id, 'reason' => "Chiesto con IVA TR {$quarter}° trimestre {$year}", 'created_by' => $actor->id]);
            }
            $claim = VatClaim::create(['company_id' => $company->id, 'kind' => 'tr', 'year' => $year, 'quarter' => $quarter, 'credit_id' => $tr->id,
                'refund_amount' => Decimal::fromMinor($refund), 'compensation_amount' => Decimal::fromMinor($comp), 'presupposto' => $d['presupposto'],
                'documentation' => ($d['documentation'] ?? '') ?: null, 'status' => 'bozza', 'deadline' => $chk['deadline'], 'created_by' => $actor->id]);
            if ($refund > 0) {
                VatCreditMovement::create(['company_id' => $company->id, 'credit_id' => $tr->id, 'destination' => 'rimborso', 'amount' => Decimal::fromMinor($refund), 'status' => 'prenotata',
                    'claim_id' => $claim->id, 'created_by' => $actor->id]);
            }
            Audit::record('credit.tr_requested', $actor, $company, $claim, data: ['year' => $year, 'quarter' => $quarter, 'refund' => $claim->refund_amount, 'compensation' => $claim->compensation_amount]);

            return $claim;
        });
    }

    /** Registrare nel Registro crediti un credito annuale dalla dichiarazione (es. anno precedente). */
    public function registerAnnual(Company $company, User $actor, array $d): VatCredit
    {
        $cents = Decimal::toMinor(str_replace(',', '.', (string) $d['amount']));
        if ($cents <= 0) {
            RuleViolation::fail(['amount' => 'Importo non valido.']);
        }
        $c = VatCredit::create(['company_id' => $company->id, 'kind' => 'annuale', 'origin' => 'dichiarazione', 'year' => (int) $d['year'], 'amount' => Decimal::fromMinor($cents),
            'description' => ($d['description'] ?? '') ?: 'Credito IVA annuale '.$d['year'].' da dichiarazione', 'filed_at' => ($d['filed_at'] ?? '') ?: null, 'visto' => (bool) ($d['visto'] ?? false),
            'visto_exempt_limit' => ($d['visto_exempt_limit'] ?? '') !== '' && isset($d['visto_exempt_limit']) ? Decimal::fromMinor(Decimal::toMinor(str_replace(',', '.', (string) $d['visto_exempt_limit']))) : null,
            'rule_version' => (Rules::for((int) $d['year'] + 1) ?? Rules::for((int) $d['year']) ?? ['version' => 'n.d.'])['version'], 'created_by' => $actor->id]);
        Audit::record('credit.registered', $actor, $company, $c, data: ['year' => $c->year, 'amount' => $c->amount, 'filed_at' => $c->filed_at?->toDateString(), 'visto' => $c->visto]);

        return $c;
    }

    /** Integrare presentazione della dichiarazione / visto per un credito annuale. */
    public function updateFiling(Company $company, User $actor, VatCredit $c, array $d): void
    {
        if ($c->kind !== 'annuale') {
            RuleViolation::fail(['filed_at' => 'Solo per il credito annuale.']);
        }
        $c->update(['filed_at' => ($d['filed_at'] ?? '') ?: null, 'visto' => (bool) ($d['visto'] ?? false)]);
        Audit::record('credit.filing_updated', $actor, $company, $c, data: ['filed_at' => $c->filed_at?->toDateString(), 'visto' => $c->visto]);
    }

    /** Sblocco documentato di una quota prenotata (non legata a una delega o a una pratica presentata). */
    public function release(Company $company, User $actor, VatCreditMovement $m, string $reason): void
    {
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo dello sblocco.']);
        }
        DB::transaction(function () use ($company, $actor, $m, $reason) {
            $m = VatCreditMovement::whereKey($m->id)->lockForUpdate()->first();
            if ($m->status !== 'prenotata') {
                RuleViolation::fail(['reason' => 'Si sblocca solo una quota prenotata.']);
            }
            if ($m->f24_id && $m->f24?->status !== 'scartato') {
                RuleViolation::fail(['reason' => 'La quota è nella delega F24 n. '.$m->f24_id.': per sbloccarla segna la delega come scartata.']);
            }
            if ($m->claim_id && in_array($m->claim?->status, ['richiesto', 'erogato'], true)) {
                RuleViolation::fail(['reason' => 'La pratica di rimborso è già presentata.']);
            }
            if ($m->destination === 'riporto' && $m->target_year && SettlementCalculator::latest($company, $m->target_year, $m->target_period)) {
                RuleViolation::fail(['reason' => 'La liquidazione di destinazione è confermata: il riporto è utilizzato.']);
            }
            $m->update(['status' => 'sbloccata', 'reason' => trim($reason)]);
            if ($m->claim_id && $m->claim->status === 'bozza') {
                $m->claim->update(['status' => 'respinto', 'outcome_notes' => 'Annullata con sblocco: '.trim($reason), 'outcome_at' => now()->toDateString()]);
            }
            Audit::record('credit.released', $actor, $company, $m, trim($reason), ['amount' => $m->amount, 'destination' => $m->destination]);
        });
    }

    private static function it(string $iso): string
    {
        return implode('/', array_reverse(explode('-', $iso)));
    }
}
