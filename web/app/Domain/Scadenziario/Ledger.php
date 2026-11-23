<?php

namespace App\Domain\Scadenziario;

use App\Models\Allocation;
use App\Models\Installment;
use App\Models\Invoice;
use App\Support\Decimal;

/**
 * Calcolo di importo compensato, residuo, stato di pagamento e scadenza – anche a una data di riferimento.
 * Stato di pagamento e scadenza del residuo aperto sono due caratteristiche indipendenti.
 */
final class Ledger
{
    /** Attribuzioni valide di una rata (come destinazione o come nota di credito compensativa), eventualmente fino alla data di riferimento. */
    public static function validAllocations(Installment $i, ?string $asOf = null)
    {
        $q = Allocation::query()->where('status', 'valida')
            ->where(fn ($w) => $w->where('installment_id', $i->id)->orWhere('credit_installment_id', $i->id))
            ->where(fn ($w) => $w->whereNull('movement_id')->orWhereHas('movement', fn ($m) => $m->where('status', 'valida')));
        if ($asOf) {
            $q->where('allocation_date', '<=', $asOf);
        }

        return $q->orderBy('allocation_date')->orderBy('id')->get();
    }

    public static function settledCents(Installment $i, ?string $asOf = null): int
    {
        return self::validAllocations($i, $asOf)->sum(fn ($a) => Decimal::toMinor((string) $a->amount));
    }

    /**
     * Stato di una rata.
     *
     * @return array{amount: int, settled: int, residual: int, due: string, paid: string}
     */
    public static function installmentState(Installment $i, ?string $asOf = null): array
    {
        $amount = Decimal::toMinor((string) $i->amount);
        $settled = self::settledCents($i, $asOf);
        $residual = $amount - $settled;
        $today = $asOf ?? now()->toDateString();
        $due = $residual <= 0 ? 'saldata' : (! $i->due_date ? 'da_definire' : ($i->due_date->toDateString() < $today ? 'scaduta' : ($i->due_date->toDateString() === $today ? 'oggi' : 'a_scadere')));
        $paid = $residual <= 0 ? 'saldata' : ($settled > 0 ? 'parziale' : 'aperta');

        return compact('amount', 'settled', 'residual', 'due', 'paid');
    }

    /**
     * Stato di una fattura: stato di pagamento (Aperta, Parzialmente, Saldata, Da verificare) e
     * scadenza del residuo aperto (rata peggiore con residuo: Scaduta > In scadenza oggi > Da definire > A scadere).
     *
     * @return array{amount: int, settled: int, residual: int, paid: string, due: ?string, check: bool, amount_check: bool}
     */
    public static function invoiceState(Invoice $inv, ?string $asOf = null): array
    {
        $states = $inv->installments->map(fn ($i) => self::installmentState($i, $asOf));
        $amount = $states->sum('amount');
        $settled = $states->sum('settled');
        $residual = $states->sum('residual');
        $paid = $residual <= 0 ? 'saldata' : ($settled > 0 ? 'parziale' : 'aperta');
        $rank = ['scaduta' => 4, 'oggi' => 3, 'da_definire' => 2, 'a_scadere' => 1];
        $due = $states->filter(fn ($s) => $s['residual'] > 0)->pluck('due')->sortByDesc(fn ($d) => $rank[$d] ?? 0)->first();

        return ['amount' => $amount, 'settled' => $settled, 'residual' => $residual, 'paid' => $inv->payment_check ? 'da_verificare' : $paid, 'due' => $due,
            'check' => (bool) $inv->payment_check, 'amount_check' => (bool) $inv->amount_check];
    }

    public const PAID_LABELS = ['aperta' => 'Aperta', 'parziale' => 'Parzialmente %s', 'saldata' => 'Saldata', 'da_verificare' => 'Da verificare'];

    public const DUE_LABELS = ['da_definire' => 'Da definire', 'a_scadere' => 'A scadere', 'oggi' => 'In scadenza oggi', 'scaduta' => 'Scaduta', 'saldata' => '—'];

    public static function paidLabel(string $paid, bool $sale): string
    {
        return sprintf(self::PAID_LABELS[$paid] ?? $paid, $sale ? 'incassata' : 'pagata');
    }
}
