<?php

namespace App\Domain\Scadenziario;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Enums\CompanyType;
use App\Enums\InvoiceDirection;
use App\Models\Allocation;
use App\Models\Company;
use App\Models\Installment;
use App\Models\MoneyMovement;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Pagamenti effettivi: un movimento di denaro viene attribuito a una o più rate dello stesso partner commerciale.
 * Gli importi non attribuiti restano come «Importo da abbinare». Le richieste ripetute (request_id) non generano un secondo pagamento.
 */
final class PaymentService
{
    public function __construct(private FiscalService $fiscal) {}

    /**
     * @param  array{kind: string, movement_date: string, amount: string, method?: ?string, bank_ref?: ?string, notes?: ?string, payer_date?: ?string, payer_year_rule?: bool, request_id?: ?string, allocations: list<array{installment_id: int, amount: string}>}  $d
     */
    public function record(Company $company, User $actor, array $d): MoneyMovement
    {
        return DB::transaction(function () use ($company, $actor, $d) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            if (! empty($d['request_id']) && ($prev = MoneyMovement::where('company_id', $company->id)->where('request_id', $d['request_id'])->first())) {
                if (hash('sha256', json_encode($this->signature($prev))) !== hash('sha256', json_encode($this->signatureFromInput($d)))) {
                    RuleViolation::fail(['request_id' => 'Questo invio è già stato usato con dati diversi: nessuna seconda registrazione del pagamento. Ricarica la pagina.']);
                }
                $prev->replayed = true;

                return $prev;
            }
            $kind = $d['kind'];
            $amount = Decimal::toMinor(str_replace(',', '.', (string) $d['amount']));
            $errors = [];
            if ($amount <= 0) {
                $errors['amount'] = 'L’importo deve essere maggiore di zero.';
            }
            if ($d['movement_date'] > now()->toDateString()) {
                $errors['movement_date'] = 'La data non può essere nel futuro.';
            }
            [$rows, $party, $allocErrors] = $this->checkAllocations($company, $kind, $d['allocations'] ?? []);
            $errors += $allocErrors;
            $sum = array_sum(array_column($rows, 'cents'));
            if (! $allocErrors && $sum > $amount) {
                $errors['allocations'] = 'Gli importi abbinati superano l’importo del movimento.';
            }
            $payerDate = ($d['payer_date'] ?? '') ?: null;
            if ($payerDate && ($kind !== 'incasso' || $company->tipologia !== CompanyType::Professionista)) {
                $errors['payer_date'] = 'La data di pagamento del sostituto d’imposta vale solo per gli incassi di un professionista.';
            }
            if ($errors) {
                RuleViolation::fail($errors);
            }

            $m = MoneyMovement::create([
                'company_id' => $company->id, 'kind' => $kind, 'party_key' => $party['key'], 'party_name' => $party['name'], 'movement_date' => $d['movement_date'],
                'amount' => Decimal::fromMinor($amount), 'method' => ($d['method'] ?? '') ?: null, 'bank_ref' => ($d['bank_ref'] ?? '') ?: null, 'notes' => ($d['notes'] ?? '') ?: null,
                'payer_date' => $payerDate, 'payer_year_rule' => $payerDate ? (bool) ($d['payer_year_rule'] ?? true) : false,
                'request_id' => ($d['request_id'] ?? '') ?: null, 'created_by' => $actor->id, 'status' => 'valida',
            ]);
            $this->writeAllocations($company, $actor, $m, $rows);
            Audit::record($kind === 'incasso' ? 'movement.incasso' : 'movement.pagamento', $actor, $company, $m, data: ['amount' => $m->amount, 'party' => $party['name'], 'allocated' => Decimal::fromMinor($sum)]);

            return $m;
        });
    }

    /** Attribuire il residuo di un movimento ad altre rate dello stesso partner. */
    public function allocate(Company $company, MoneyMovement $m, User $actor, array $allocations): void
    {
        DB::transaction(function () use ($company, $m, $actor, $allocations) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            if ($m->status !== 'valida') {
                RuleViolation::fail(['allocations' => 'Il movimento è stornato.']);
            }
            [$rows, $party, $errors] = $this->checkAllocations($company, $m->kind, $allocations, $m->party_key);
            if ($errors) {
                RuleViolation::fail($errors);
            }
            if (array_sum(array_column($rows, 'cents')) > self::unallocatedCents($m)) {
                RuleViolation::fail(['allocations' => 'Gli importi superano l’importo da abbinare del movimento.']);
            }
            $this->writeAllocations($company, $actor, $m, $rows);
            Audit::record('movement.allocated', $actor, $company, $m, data: ['allocations' => count($rows)]);
        });
    }

    /** Compensazione con una nota di credito dello stesso partner: nessun flusso di denaro, riduzione tracciabile della partita aperta. */
    public function compensate(Company $company, User $actor, int $installmentId, int $creditInstallmentId, string $amount, string $date): Allocation
    {
        return DB::transaction(function () use ($company, $actor, $installmentId, $creditInstallmentId, $amount, $date) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $target = Installment::where('company_id', $company->id)->with('invoice')->findOrFail($installmentId);
            $credit = Installment::where('company_id', $company->id)->with('invoice')->findOrFail($creditInstallmentId);
            $cents = Decimal::toMinor(str_replace(',', '.', $amount));
            $e = [];
            if ($credit->invoice->doc_type !== 'TD04' || $target->invoice->doc_type === 'TD04') {
                $e['credit'] = 'Scegli una nota di credito da compensare con una fattura.';
            } elseif ($credit->invoice->direction !== $target->invoice->direction || ScheduleService::partyKey($credit->invoice) !== ScheduleService::partyKey($target->invoice)) {
                $e['credit'] = 'La nota di credito deve essere dello stesso cliente o fornitore e dello stesso registro.';
            }
            if ($cents <= 0 || $cents > Ledger::installmentState($target)['residual'] || $cents > Ledger::installmentState($credit)['residual']) {
                $e['amount'] = 'Importo non valido: al massimo il residuo della fattura e della nota di credito.';
            }
            if ($date > now()->toDateString()) {
                $e['date'] = 'La data non può essere nel futuro.';
            }
            if ($e) {
                RuleViolation::fail($e);
            }
            $a = Allocation::create(['company_id' => $company->id, 'installment_id' => $target->id, 'credit_installment_id' => $credit->id, 'type' => 'nota_credito', 'status' => 'valida',
                'allocation_date' => $date, 'amount' => Decimal::fromMinor($cents), 'created_by' => $actor->id]);
            Audit::record('allocation.compensation', $actor, $company, $a, data: ['invoice' => $target->invoice->number, 'credit_note' => $credit->invoice->number, 'amount' => $a->amount]);
            $this->fiscal->sync($target->invoice);
            $this->fiscal->sync($credit->invoice);

            return $a;
        });
    }

    /** Storno di un pagamento registrato per errore: residuo e valutazione fiscale vengono ricalcolati. */
    public function reverse(Company $company, MoneyMovement $m, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo dello storno.']);
        }
        if ($m->status !== 'valida') {
            RuleViolation::fail(['reason' => 'Il movimento è già stornato.']);
        }
        DB::transaction(function () use ($company, $m, $actor, $reason) {
            $m->update(['status' => 'stornata', 'reversal_reason' => trim($reason), 'reversed_at' => now()]);
            $invoices = $m->allocations()->with('installment.invoice')->get()->map(fn ($a) => $a->installment->invoice)->unique('id');
            Audit::record('movement.reversed', $actor, $company, $m, trim($reason), ['amount' => $m->amount]);
            foreach ($invoices as $inv) {
                $this->fiscal->sync($inv);
            }
        });
    }

    public function reverseAllocation(Company $company, Allocation $a, User $actor, string $reason): void
    {
        if (trim($reason) === '' || $a->status !== 'valida') {
            RuleViolation::fail(['reason' => trim($reason) === '' ? 'Indica il motivo dello storno.' : 'Abbinamento già stornato.']);
        }
        DB::transaction(function () use ($company, $a, $actor, $reason) {
            $a->update(['status' => 'stornata', 'reversal_reason' => trim($reason)]);
            Audit::record('allocation.reversed', $actor, $company, $a, trim($reason));
            $this->fiscal->sync($a->installment->invoice);
            if ($a->creditInstallment) {
                $this->fiscal->sync($a->creditInstallment->invoice);
            }
        });
    }

    public static function unallocatedCents(MoneyMovement $m): int
    {
        return Decimal::toMinor((string) $m->amount) - $m->allocations()->where('status', 'valida')->get()->sum(fn ($a) => Decimal::toMinor((string) $a->amount));
    }

    /** @return array{0: list<array{inst: Installment, cents: int}>, 1: array{key: string, name: string}, 2: array<string, string>} */
    private function checkAllocations(Company $company, string $kind, array $allocations, ?string $partyKey = null): array
    {
        $rows = [];
        $errors = [];
        $party = $partyKey ? ['key' => $partyKey, 'name' => ''] : null;
        $direction = $kind === 'incasso' ? InvoiceDirection::Vendita : InvoiceDirection::Acquisto;
        if (! $allocations) {
            $errors['allocations'] = 'Abbina il movimento ad almeno una rata.';
        }
        $seen = [];
        foreach (array_values($allocations) as $k => $a) {
            // Separazione dei mandanti: solo rate di questa azienda.
            $inst = Installment::where('company_id', $company->id)->with('invoice')->find((int) ($a['installment_id'] ?? 0));
            $cents = Decimal::toMinor(str_replace(',', '.', (string) ($a['amount'] ?? '0')));
            if (! $inst) {
                $errors["allocations.{$k}.installment_id"] = 'Rata non trovata.';

                continue;
            }
            if (isset($seen[$inst->id])) {
                $errors["allocations.{$k}.installment_id"] = 'Rata ripetuta.';
            }
            $seen[$inst->id] = true;
            $inv = $inst->invoice;
            if ($inv->direction !== $direction) {
                $errors["allocations.{$k}.installment_id"] = $kind === 'incasso' ? 'Gli incassi si abbinano alle fatture di vendita.' : 'I pagamenti si abbinano alle fatture di acquisto.';
            } elseif ($inv->doc_type === 'TD04') {
                $errors["allocations.{$k}.installment_id"] = 'Le note di credito si compensano con le fatture (“Compensa nota di credito”).';
            }
            $key = ScheduleService::partyKey($inv);
            if ($party === null) {
                $party = ['key' => $key, 'name' => ScheduleService::partyName($inv)];
            } elseif ($party['key'] !== $key) {
                $errors["allocations.{$k}.installment_id"] = 'Un movimento può saldare solo fatture dello stesso cliente o fornitore.';
            }
            if ($party['name'] === '') {
                $party['name'] = ScheduleService::partyName($inv);
            }
            $residual = Ledger::installmentState($inst)['residual'];
            if ($cents <= 0) {
                $errors["allocations.{$k}.amount"] = 'Importo maggiore di zero.';
            } elseif ($cents > $residual) {
                $errors["allocations.{$k}.amount"] = 'Supera il residuo della rata ('.str_replace('.', ',', Decimal::fromMinor($residual)).').';
            }
            $rows[] = ['inst' => $inst, 'cents' => $cents];
        }

        return [$rows, $party ?? ['key' => '', 'name' => ''], $errors];
    }

    private function writeAllocations(Company $company, User $actor, MoneyMovement $m, array $rows): void
    {
        foreach ($rows as $r) {
            Allocation::create(['company_id' => $company->id, 'installment_id' => $r['inst']->id, 'movement_id' => $m->id, 'type' => 'pagamento', 'status' => 'valida',
                'allocation_date' => $m->movement_date->toDateString(), 'amount' => Decimal::fromMinor($r['cents']), 'created_by' => $actor->id]);
        }
        foreach (collect($rows)->map(fn ($r) => $r['inst']->invoice)->unique('id') as $inv) {
            $this->fiscal->sync($inv);
        }
    }

    /** Elementi di confronto per le ripetizioni della stessa request_id. */
    private function signature(MoneyMovement $m): array
    {
        return [$m->kind, $m->movement_date->toDateString(), (string) $m->amount, $m->allocations()->orderBy('id')->get()->map(fn ($a) => [$a->installment_id, (string) $a->amount])->all()];
    }

    private function signatureFromInput(array $d): array
    {
        return [$d['kind'], $d['movement_date'], Decimal::fromMinor(Decimal::toMinor(str_replace(',', '.', (string) $d['amount']))),
            array_map(fn ($a) => [(int) $a['installment_id'], Decimal::fromMinor(Decimal::toMinor(str_replace(',', '.', (string) $a['amount'])))], array_values($d['allocations'] ?? []))];
    }
}
