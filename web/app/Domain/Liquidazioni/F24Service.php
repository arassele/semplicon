<?php

namespace App\Domain\Liquidazioni;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Models\Company;
use App\Models\F24Draft;
use App\Models\User;
use App\Models\VatCredit;
use App\Models\VatCreditMovement;
use App\Models\VatSettlement;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Bozza della delega F24 (Sezione Erario) relativa a una liquidazione a debito confermata. Semplicon prepara solo il documento:
 * nessuna trasmissione, nessun addebito. La scadenza resta aperta finché la delega non è registrata come «Presentato».
 */
final class F24Service
{
    public const STATUS = ['bozza' => 'Bozza generata', 'presentato' => 'Presentato', 'scartato' => 'Scartato', 'addebito_confermato' => 'Addebito confermato'];

    public function __construct(private CreditService $credits) {}

    public static function active(VatSettlement $s): ?F24Draft
    {
        return F24Draft::where('settlement_id', $s->id)->whereIn('status', ['bozza', 'presentato', 'addebito_confermato'])->latest('id')->first();
    }

    /**
     * @param  list<array{credit_id: int, amount: string}>  $compensations
     */
    public function generate(Company $company, User $actor, VatSettlement $s, array $compensations = [], ?string $requestId = null): F24Draft
    {
        return DB::transaction(function () use ($company, $actor, $s, $compensations, $requestId) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            if ($requestId && ($prev = F24Draft::where('company_id', $company->id)->where('request_id', $requestId)->first())) {
                $prev->replayed = true;

                return $prev;
            }
            $s = VatSettlement::whereKey($s->id)->firstOrFail();
            if ($s->status !== 'confermata' || $s->review_required) {
                RuleViolation::fail(['f24' => $s->review_required ? 'Liquidazione da riesaminare: conferma prima la nuova versione.' : 'La liquidazione è stata sostituita da una nuova versione.']);
            }
            $due = Decimal::toMinor((string) $s->amount_due);
            if ($due <= 0) {
                RuleViolation::fail(['f24' => $s->result === 'debito' ? 'Debito rinviato al periodo successivo: nessuna delega in questo periodo.' : 'Nessun importo da versare: il periodo si chiude senza F24.']);
            }
            if ($existing = self::active($s)) {
                $existing->replayed = true; // una sola delega attiva per liquidazione

                return $existing;
            }
            $code = Rules::code($s->year, $s->period, $s->periodicity, $s->quarterly_type);
            $rules = Rules::for((int) substr($s->due_date->toDateString(), 0, 4)) ?? Rules::for($s->year);
            $lines = [['section' => 'Erario', 'code' => $code['code'], 'label' => $code['label'].' '.$s->data['period_label'], 'rateazione' => $code['rateazione'], 'year' => $s->year,
                'debit' => Decimal::fromMinor($due), 'credit' => '0.00']];
            $credit = 0;
            $movements = [];
            $errors = [];
            foreach (array_values($compensations) as $k => $row) {
                $amount = Decimal::toMinor(str_replace(',', '.', (string) $row['amount']));
                if ($amount <= 0) {
                    continue;
                }
                $c = VatCredit::where('company_id', $company->id)->lockForUpdate()->find((int) $row['credit_id']);
                if (! $c) {
                    $errors["compensations.{$k}"] = 'Credito non trovato.';

                    continue;
                }
                // Usare per prime le quote già prenotate di questo credito non ancora assegnate a una delega.
                $reserved = VatCreditMovement::where('credit_id', $c->id)->where('destination', 'compensazione')->where('status', 'prenotata')->whereNull('f24_id')->lockForUpdate()->orderBy('id')->get();
                $resCents = $reserved->sum(fn ($m) => Decimal::toMinor((string) $m->amount));
                $fresh = max(0, $amount - $resCents);
                $av = $this->credits->availability($c, 'compensazione', $amount, $s->due_date->toDateString());
                $reasons = array_filter($av['reasons'], fn ($x) => ! str_contains($x, 'residuo'));
                if ($fresh > $c->residualCents()) {
                    $reasons[] = 'Supera il residuo disponibile ('.SettlementService::eur($c->residualCents() + $resCents).').';
                }
                if ($reasons) {
                    $errors["compensations.{$k}"] = $c->description.': '.implode(' ', $reasons);

                    continue;
                }
                $left = $amount;
                foreach ($reserved as $m) {
                    if ($left <= 0) {
                        break;
                    }
                    $mc = Decimal::toMinor((string) $m->amount);
                    if ($mc > $left) {
                        // Utilizzo parziale: il resto resta come prenotazione a sé.
                        VatCreditMovement::create(['company_id' => $company->id, 'credit_id' => $c->id, 'destination' => 'compensazione', 'amount' => Decimal::fromMinor($mc - $left), 'status' => 'prenotata', 'created_by' => $m->created_by]);
                        $m->update(['amount' => Decimal::fromMinor($left)]);
                        $mc = $left;
                    }
                    $movements[] = $m;
                    $left -= $mc;
                }
                if ($left > 0) {
                    $movements[] = VatCreditMovement::create(['company_id' => $company->id, 'credit_id' => $c->id, 'destination' => 'compensazione', 'amount' => Decimal::fromMinor($left), 'status' => 'prenotata', 'created_by' => $actor->id]);
                }
                $credit += $amount;
                $lines[] = ['section' => 'Erario', 'code' => $c->kind === 'trimestrale' ? $rules['codes']['tr_credit'][$c->period] : $rules['codes']['annual'], 'label' => 'Compensazione '.$c->description,
                    'rateazione' => null, 'year' => $c->year, 'debit' => '0.00', 'credit' => Decimal::fromMinor($amount), 'credit_id' => $c->id];
            }
            if ($credit > $due) {
                $errors['compensations'] = 'I crediti in compensazione superano il debito: il saldo della delega non può essere negativo.';
            }
            if ($errors) {
                RuleViolation::fail($errors);
            }
            $f = F24Draft::create(['company_id' => $company->id, 'settlement_id' => $s->id, 'due_date' => $s->due_date, 'status' => 'bozza', 'lines' => $lines,
                'total_debit' => Decimal::fromMinor($due), 'total_credit' => Decimal::fromMinor($credit), 'balance' => Decimal::fromMinor($due - $credit),
                'rule_version' => $rules['version'], 'request_id' => $requestId, 'created_by' => $actor->id]);
            foreach ($movements as $m) {
                $m->update(['f24_id' => $f->id]);
            }
            Audit::record('f24.generated', $actor, $company, $f, data: ['settlement' => $s->id, 'debit' => $f->total_debit, 'credit' => $f->total_credit, 'balance' => $f->balance, 'due_date' => $f->due_date->toDateString()]);

            return $f;
        });
    }

    public function present(Company $company, User $actor, F24Draft $f, string $date, ?string $protocol, ?string $outcome = null): void
    {
        DB::transaction(function () use ($company, $actor, $f, $date, $protocol, $outcome) {
            $f = F24Draft::whereKey($f->id)->lockForUpdate()->first();
            if ($f->status !== 'bozza') {
                RuleViolation::fail(['status' => 'Solo una bozza può essere segnata come presentata.']);
            }
            if ($date > now()->toDateString()) {
                RuleViolation::fail(['presented_at' => 'La data non può essere nel futuro.']);
            }
            $f->update(['status' => 'presentato', 'presented_at' => $date, 'protocol' => $protocol ?: null, 'outcome' => $outcome ?: null]);
            $f->movements()->where('status', 'prenotata')->update(['status' => 'utilizzata']);
            Audit::record('f24.presented', $actor, $company, $f, data: ['date' => $date, 'protocol' => $protocol]);
        });
    }

    public function reject(Company $company, User $actor, F24Draft $f, string $reason): void
    {
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo dello scarto.']);
        }
        DB::transaction(function () use ($company, $actor, $f, $reason) {
            $f = F24Draft::whereKey($f->id)->lockForUpdate()->first();
            if (! in_array($f->status, ['bozza', 'presentato'], true)) {
                RuleViolation::fail(['status' => 'Delega non scartabile in questo stato.']);
            }
            $f->update(['status' => 'scartato', 'outcome' => trim($reason)]);
            // Sblocco documentato: le quote tornano disponibili, la delega resta con la motivazione.
            $f->movements()->whereIn('status', ['prenotata', 'utilizzata'])->update(['status' => 'sbloccata', 'reason' => 'Sblocco dopo scarto della delega n. '.$f->id.': '.trim($reason)]);
            Audit::record('f24.rejected', $actor, $company, $f, trim($reason));
        });
    }

    public function confirmDebit(Company $company, User $actor, F24Draft $f, string $date): void
    {
        if ($f->status !== 'presentato' || Decimal::toMinor((string) $f->balance) <= 0) {
            RuleViolation::fail(['status' => 'L’addebito si conferma per una delega presentata con saldo a debito.']);
        }
        $f->update(['status' => 'addebito_confermato', 'debit_confirmed_at' => $date]);
        Audit::record('f24.debit_confirmed', $actor, $company, $f, data: ['date' => $date]);
    }
}
