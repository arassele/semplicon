<?php

namespace App\Domain\Scadenziario;

use App\Enums\InvoiceDirection;
use App\Enums\SemplificataMethod;
use App\Enums\VatRegime;
use App\Models\Allocation;
use App\Models\Company;
use App\Models\FiscalEntry;
use App\Models\Invoice;
use App\Models\VatRegistration;
use App\Support\Decimal;

/**
 * Attribuzione fiscale per anno d'imposta a livello di componenti imputate (righe di registrazione).
 *
 * Base per ogni evento è il profilo confermato dell'anno di riferimento:
 * - semplificata per cassa e professionista: incassi/pagamenti effettivi (ogni pagamento parziale con la propria data);
 * - semplificata «per competenza» = presunzione di incasso/pagamento alla registrazione (art. 18 c. 5 DPR 600/1973): attribuzione alla registrazione,
 *   i pagamenti successivi riducono solo il residuo commerciale;
 * - senza profilo confermato, forfettario o in caso di cambio di regime: «Da verificare».
 * Le voci vengono ricalcolate per fattura da registrazione e attribuzioni valide. Per ogni componente viene attribuito al massimo
 * l'importo totale (protezione dalla doppia rilevazione, anche dopo un cambio di profilo); quote cumulate in centesimi, il
 * pagamento finale compensa i residui di arrotondamento.
 */
final class FiscalService
{
    /** @return array{0: 'cassa'|'registrazione'|'da_verificare', 1: string} */
    public static function modeFor(Company $company, int $year): array
    {
        $p = $company->activeVatProfile($year);
        if (! $p) {
            return ['da_verificare', "Nessun regime IVA confermato per il {$year}: imputazione da verificare."];
        }
        // Indicazione del committente (21.11.2026): i professionisti ai fini fiscali sempre per cassa, anche se forfettari.
        if ($p->regime === VatRegime::Professionisti) {
            return ['cassa', 'Professionista: compensi e spese imputati alla data di incasso o pagamento.'];
        }
        if ($p->forfettario_iva) {
            return ['da_verificare', "Soggetto forfettario nel {$year}: criterio di imputazione da verificare."];
        }
        if ($p->regime === VatRegime::Semplificata && $p->method === SemplificataMethod::Cassa) {
            return ['cassa', 'Contabilità semplificata con incassi e pagamenti effettivi.'];
        }
        if ($p->regime === VatRegime::Semplificata && $p->method === SemplificataMethod::Competenza) {
            return ['registrazione', 'Contabilità semplificata con presunzione di incasso/pagamento alla registrazione.'];
        }

        return ['da_verificare', "Regime {$year} non determinabile: imputazione da verificare."];
    }

    public function syncCompany(Company $company): void
    {
        $company->invoices()->where(fn ($q) => $q->whereHas('installments.allocations')->orWhereHas('registrations'))->get()->each(fn ($i) => $this->sync($i));
        // Le note di credito usate solo come compensazione sono già incluse tramite registrations.
    }

    public function sync(Invoice $invoice): void
    {
        $invoice->refresh();
        FiscalEntry::where('invoice_id', $invoice->id)->delete();
        $company = $invoice->company()->first();
        $reg = VatRegistration::where('active_invoice_id', $invoice->id)->with('lines.account')->first();
        $credit = $invoice->doc_type === 'TD04';
        $sign = $credit ? -1 : 1;
        $sale = ($reg?->direction ?? $invoice->direction) === InvoiceDirection::Vendita;
        $nature = $sale ? 'ricavo' : 'costo';

        // Componenti imputate: ricavo = imponibile; costo = imponibile + IVA non detraibile. Limite di deducibilità del conto.
        $components = [];
        if ($reg) {
            foreach ($reg->lines as $k => $l) {
                if ($l->account?->type === 'patrimoniale') {
                    continue; // cespite: fiscalmente conta l'ammortamento (modulo Cespiti), non l'acquisto o la vendita
                }
                $t = Decimal::toMinor((string) $l->taxable);
                $nd = $sale ? 0 : Decimal::toMinor((string) $l->tax) - Decimal::toMinor((string) $l->deductible_tax);
                $pct = $l->account?->deducibilita_pct;
                $components[] = ['k' => $k, 'account_id' => $l->account_id, 'amount' => $sign * ($t + $nd), 'pct' => $pct === null ? 10000 : Decimal::toMinor((string) $pct),
                    'pct_note' => $pct === null ? ($l->account?->deducibilita_nota ? 'deducibilità “'.$l->account->deducibilita_nota.'”: considerata per intero' : null) : 'deducibilità '.str_replace('.', ',', rtrim(rtrim((string) $pct, '0'), '.')).'%'];
            }
        }

        // Eventi di pagamento: attribuzioni valide (come fattura o come nota di credito compensativa), in ordine cronologico.
        $instIds = $invoice->installments()->pluck('id');
        $payable = $invoice->installments()->get()->sum(fn ($i) => Decimal::toMinor((string) $i->amount));
        $allocs = Allocation::where('status', 'valida')
            ->where(fn ($w) => $w->whereIn('installment_id', $instIds)->orWhereIn('credit_installment_id', $instIds))
            ->where(fn ($w) => $w->whereNull('movement_id')->orWhereHas('movement', fn ($m) => $m->where('status', 'valida')))
            ->with('movement')->orderBy('allocation_date')->orderBy('id')->get();

        $events = [];
        if ($reg) {
            $events[] = ['type' => 'reg', 'date' => $reg->registration_date->toDateString(), 'order' => 0];
        }
        foreach ($allocs as $a) {
            $events[] = ['type' => 'pay', 'date' => $a->allocation_date->toDateString(), 'order' => 1, 'a' => $a];
        }
        usort($events, fn ($x, $y) => [$x['date'], $x['order'], $x['a']->id ?? 0] <=> [$y['date'], $y['order'], $y['a']->id ?? 0]);

        $attributed = array_fill(0, count($components), 0);
        $settled = 0;
        $regMode = $reg ? self::modeFor($company, (int) substr($reg->registration_date->toDateString(), 0, 4))[0] : null;
        $rows = [];
        foreach ($events as $ev) {
            if ($ev['type'] === 'reg') {
                [$mode, $why] = self::modeFor($company, (int) substr($ev['date'], 0, 4));
                if ($mode !== 'registrazione') {
                    continue;
                }
                foreach ($components as $i => $c) {
                    $delta = $c['amount'] - $attributed[$i];
                    $attributed[$i] = $c['amount'];
                    $rows[] = $this->row($invoice, $c, $delta, $ev['date'], 'registrazione', $nature, "{$why} Registrazione del ".self::it($ev['date']).' (prot. '.$reg->protocol.'/'.$reg->year.').', registration: $reg->id);
                }

                continue;
            }
            /** @var Allocation $a */
            $a = $ev['a'];
            $settled += Decimal::toMinor((string) $a->amount);
            $m = $a->movement;
            $fiscalDate = $ev['date'];
            $why = $a->type === 'nota_credito' ? 'Compensazione con nota di credito del '.self::it($ev['date']).'.' : ($a->movement?->kind === 'incasso' ? 'Incasso' : 'Pagamento').' del '.self::it($ev['date']).'.';
            // Professionista: pagamento del sostituto d'imposta nell'anno precedente, accredito nell'anno successivo – attribuzione confermata all'anno del sostituto.
            if ($m && $m->payer_date && $m->payer_year_rule && substr($m->payer_date->toDateString(), 0, 4) !== substr($ev['date'], 0, 4)) {
                $fiscalDate = $m->payer_date->toDateString();
                $why = 'Compenso pagato dal sostituto d’imposta il '.self::it($fiscalDate).', accreditato il '.self::it($ev['date']).': imputato al '.substr($fiscalDate, 0, 4).' come certificato dal sostituto (verifica confermata dall’utente).';
            }
            $year = (int) substr($fiscalDate, 0, 4);
            [$mode, $modeWhy] = self::modeFor($company, $year);
            if (! $reg) {
                // Senza imputazione nessuna componente: quota dell'imponibile come caso da verificare.
                $share = $payable ? $this->share($sign * abs(Decimal::toMinor((string) $invoice->taxable_total)), $settled, $payable) - array_sum(array_column(array_filter($rows, fn ($r) => $r['basis'] === 'da_verificare'), 'raw')) : 0;
                $rows[] = $this->row($invoice, ['k' => 0, 'account_id' => null, 'pct' => 10000, 'pct_note' => null], $share, $fiscalDate, 'da_verificare', $nature, "{$why} Fattura non ancora registrata: conti non assegnati, imputazione da verificare.", $a->id);

                continue;
            }
            foreach ($components as $i => $c) {
                $target = $settled >= $payable ? $c['amount'] : $this->share($c['amount'], $settled, $payable);
                $delta = $c['amount'] >= 0 ? max(0, $target - $attributed[$i]) : min(0, $target - $attributed[$i]);
                if ($regMode === 'registrazione' && $delta === 0) {
                    // Presunzione: l'importo è già attribuito alla registrazione, nessuna seconda rilevazione.
                    $rows[] = $this->row($invoice, $c, 0, $fiscalDate, 'registrazione', $nature, "{$why} Nessun nuovo importo: già considerato alla registrazione (presunzione di incasso/pagamento).", $a->id);

                    continue;
                }
                if ($mode === 'registrazione' || ($mode === 'cassa' && $regMode === 'registrazione')) {
                    $basis = 'da_verificare';
                    $reason = "{$why} Regime diverso tra registrazione e ".($sale ? 'incasso' : 'pagamento').': imputazione da verificare.';
                } elseif ($mode === 'cassa') {
                    $basis = 'cassa';
                    $reason = "{$why} {$modeWhy}";
                } else {
                    $basis = 'da_verificare';
                    $reason = "{$why} {$modeWhy}";
                }
                $attributed[$i] += $delta;
                $rows[] = $this->row($invoice, $c, $delta, $fiscalDate, $basis, $nature, $reason, $a->id);
            }
        }
        foreach ($rows as $r) {
            unset($r['raw']);
            FiscalEntry::create($r);
        }
    }

    /** Quota cumulata in centesimi, mezzo centesimo in valore assoluto. */
    private function share(int $amount, int $settled, int $payable): int
    {
        $x = abs($amount) * $settled * 2;
        $r = intdiv($x + $payable, 2 * $payable);

        return $amount < 0 ? -$r : $r;
    }

    private function row(Invoice $inv, array $c, int $delta, string $date, string $basis, string $nature, string $reason, ?int $allocation = null, ?int $registration = null): array
    {
        $fiscal = intdiv(abs($delta) * $c['pct'] * 2 + 10000, 20000) * ($delta < 0 ? -1 : 1);
        if ($c['pct_note']) {
            $reason .= ' ('.$c['pct_note'].')';
        }

        return ['company_id' => $inv->company_id, 'invoice_id' => $inv->id, 'account_id' => $c['account_id'], 'allocation_id' => $allocation, 'registration_id' => $registration,
            'component' => $c['k'], 'event_date' => $date, 'year' => (int) substr($date, 0, 4), 'basis' => $basis, 'nature' => $nature,
            'amount' => Decimal::fromMinor($delta), 'fiscal_amount' => Decimal::fromMinor($fiscal), 'reason' => $reason, 'raw' => $delta];
    }

    private static function it(string $iso): string
    {
        return implode('/', array_reverse(explode('-', $iso)));
    }
}
