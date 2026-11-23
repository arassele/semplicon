<?php

namespace App\Domain\Scadenziario;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Enums\InvoiceDirection;
use App\Models\Company;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Rate (partite aperte) per fattura. Scadenza e modalità di pagamento dall'XML sono indicazioni sull'accordo di pagamento,
 * non prova di pagamento. Le rate nascono esattamente una volta per fattura; una nuova importazione non cambia nulla.
 */
final class ScheduleService
{
    /** Importo effettivamente da pagare in centesimi: Totale − ritenuta − IVA in split payment (EsigibilitaIVA S). */
    public static function expectedPayable(Invoice $i): int
    {
        $i->loadMissing('vatSummaries');
        $total = $i->total_amount !== null ? Decimal::toMinor((string) $i->total_amount) : Decimal::toMinor((string) $i->taxable_total) + Decimal::toMinor((string) $i->tax_total);
        $split = $i->vatSummaries->where('esigibilita', 'S')->sum(fn ($s) => Decimal::toMinor((string) $s->tax));

        return abs($total - Decimal::toMinor((string) ($i->ritenuta ?? '0.00')) - $split);
    }

    /** @param list<array{mode: ?string, due_date: ?string, amount: string, condition?: ?string}> $payments */
    public function createFor(Invoice $invoice, array $payments = []): void
    {
        if ($invoice->installments()->exists()) {
            return; // non creare mai in doppio, le correzioni manuali restano
        }
        $expected = self::expectedPayable($invoice);
        if ($payments) {
            foreach (array_values($payments) as $k => $p) {
                Installment::create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'seq' => $k + 1, 'due_date' => $p['due_date'] ?? null,
                    'amount' => Decimal::fromMinor(abs(Decimal::toMinor($p['amount']))), 'payment_mode' => $p['mode'] ?? null, 'source' => 'xml']);
            }
            $sum = array_sum(array_map(fn ($p) => abs(Decimal::toMinor($p['amount'])), $payments));
            $invoice->update(['amount_check' => $sum !== $expected, 'payment_condition' => $payments[0]['condition'] ?? null]);
        } else {
            Installment::create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'seq' => 1, 'due_date' => null,
                'amount' => Decimal::fromMinor($expected), 'source' => 'calcolata']);
        }
    }

    /**
     * Impostare le rate manualmente: modificare quelle esistenti (con id), crearne di nuove, eliminare quelle mancanti, purché non abbiano attribuzioni.
     *
     * @param  list<array{id?: ?int, due_date: ?string, amount: string, payment_mode?: ?string}>  $rows
     */
    public function update(Company $company, Invoice $invoice, User $actor, array $rows): void
    {
        DB::transaction(function () use ($company, $invoice, $actor, $rows) {
            $existing = $invoice->installments()->get()->keyBy('id');
            $keep = collect($rows)->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();
            foreach ($existing as $id => $inst) {
                if (! in_array($id, $keep, true)) {
                    if ($inst->allocations()->exists() || $inst->creditAllocations()->exists()) {
                        RuleViolation::fail(['installments' => "La rata {$inst->seq} ha già incassi o pagamenti: non si può eliminare."]);
                    }
                    $inst->delete();
                }
            }
            $seq = 0;
            foreach ($rows as $k => $r) {
                $amount = Decimal::toMinor(str_replace(',', '.', (string) $r['amount']));
                if ($amount <= 0) {
                    RuleViolation::fail(["installments.{$k}.amount" => 'L’importo della rata deve essere maggiore di zero.']);
                }
                $data = ['seq' => ++$seq, 'due_date' => ($r['due_date'] ?? '') ?: null, 'amount' => Decimal::fromMinor($amount), 'payment_mode' => ($r['payment_mode'] ?? '') ?: null, 'edited' => true];
                if (! empty($r['id'])) {
                    $inst = $existing[(int) $r['id']] ?? RuleViolation::fail(['installments' => 'Rata non valida.']);
                    $settled = Ledger::settledCents($inst);
                    if ($amount < $settled) {
                        RuleViolation::fail(["installments.{$k}.amount" => 'L’importo non può essere inferiore a quanto già incassato o pagato ('.str_replace('.', ',', Decimal::fromMinor($settled)).').']);
                    }
                    $inst->update(['seq' => 1000 + $seq]); // liberare l'ordine univoco
                    $inst->fill($data)->save();
                } else {
                    Installment::create([...$data, 'company_id' => $company->id, 'invoice_id' => $invoice->id, 'seq' => 1000 + $seq, 'source' => 'manuale']);
                }
            }
            $invoice->installments()->get()->each(fn ($i) => $i->seq >= 1000 && $i->update(['seq' => $i->seq - 1000]));
            $sum = $invoice->installments()->get()->sum(fn ($i) => Decimal::toMinor((string) $i->amount));
            $invoice->update(['amount_check' => $sum !== self::expectedPayable($invoice)]);
            Audit::record('installments.updated', $actor, $company, $invoice, data: ['rates' => count($rows)]);
            app(FiscalService::class)->sync($invoice->fresh());
        });
    }

    /** Confermare stato di pagamento e importi come verificati («Stato pagamento da verificare» / «Importo da verificare»). */
    public function confirm(Company $company, Invoice $invoice, User $actor): void
    {
        $invoice->update(['payment_check' => false, 'amount_check' => false]);
        Audit::record('payment_status.confirmed', $actor, $company, $invoice);
    }

    public static function partyKey(Invoice $i): string
    {
        return $i->direction === InvoiceDirection::Vendita
            ? ($i->customer_vat ?? $i->customer_cf ?? mb_strtoupper(trim($i->customer_name)))
            : ($i->supplier_vat ?? $i->supplier_cf ?? mb_strtoupper(trim($i->supplier_name)));
    }

    public static function partyName(Invoice $i): string
    {
        return $i->direction === InvoiceDirection::Vendita ? $i->customer_name : $i->supplier_name;
    }
}
