<?php

namespace App\Domain;

use App\Domain\Liquidazioni\SettlementService;
use App\Domain\Scadenziario\FiscalService;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Models\VatRegistration;
use App\Support\Decimal;
use App\Support\VatPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Registrazione IVA delle fatture importate (I03). Regole: docs/FISCAL_RULES.md, R-REG-*.
 * Calcoli in centesimi come interi. Le registrazioni sono immutabili, correzione tramite storno.
 */
final class RegistrationService
{
    /** Avvisi di verifica dell'importazione che alla registrazione non valgono più o bloccano in modo vincolante. */
    private const REGIME_ISSUE = 'Nessun regime IVA confermato per il ';

    private const CURRENCY_ISSUE = 'Valuta ';

    /**
     * Cosa va chiarito prima della registrazione.
     *
     * @return array{blockers: list<string>, acknowledge: list<string>}
     */
    public function checks(Company $company, Invoice $invoice): array
    {
        $blockers = [];
        if (! $invoice->status->isOpen()) {
            $blockers[] = 'La fattura non è da registrare.';
        }
        if ($invoice->currency !== 'EUR' && ! $invoice->exchange_rate) {
            $blockers[] = "Fattura in {$invoice->currency}: in questa versione si registrano solo fatture in euro.";
        }
        // Avvisi che il cliente deve confermare espressamente. Regime e valuta vengono verificati in tempo reale.
        $acknowledge = array_values(array_filter($invoice->issues ?? [], fn ($m) => ! str_starts_with($m, self::REGIME_ISSUE) && ! str_starts_with($m, self::CURRENCY_ISSUE)));
        if ($invoice->doc_type === 'TD04' && (str_starts_with((string) $invoice->taxable_total, '-') || str_starts_with((string) $invoice->tax_total, '-'))) {
            $acknowledge[] = 'Nota di credito con importi negativi: verrà registrata come riduzione con gli importi in valore assoluto.';
        }

        return ['blockers' => $blockers, 'acknowledge' => $acknowledge];
    }

    /** Suggerimento per il periodo: vendite per data del documento, acquisti per data di ricezione. Da confermare dal cliente. */
    public function suggestedPeriod(Company $company, string $basisDate): ?array
    {
        $year = (int) substr($basisDate, 0, 4);
        $profile = $company->activeVatProfile($year);

        return $profile ? [...VatPeriod::ofDate($basisDate, $profile->periodicity->value), 'periodicity' => $profile->periodicity->value] : null;
    }

    /** Suggerimento per riga di riepilogo: ultimo conto usato per questa controparte, altrimenti conto standard del tipo. */
    public function suggestedAccount(Company $company, Invoice $invoice, InvoiceDirection $direction): ?int
    {
        $counterpartyVat = $direction === InvoiceDirection::Vendita ? $invoice->customer_vat : $invoice->supplier_vat;
        $last = $counterpartyVat ? DB::table('vat_registration_lines as l')
            ->join('vat_registrations as r', 'r.id', '=', 'l.vat_registration_id')
            ->join('invoices as i', 'i.id', '=', 'r.invoice_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('r.company_id', $company->id)->where('r.status', 'registrata')->where('r.direction', $direction->value)
            ->where($direction === InvoiceDirection::Vendita ? 'i.customer_vat' : 'i.supplier_vat', $counterpartyVat)
            ->where('a.active', true)->where('a.iva_rilevante', true)->orderByDesc('r.id')->value('l.account_id') : null;

        return $last ?? $company->accounts()->reorder()->where('type', self::accountType($direction))->where('active', true)->where('iva_rilevante', true)->orderByDesc('is_default')->orderBy('code')->value('id');
    }

    public static function accountType(InvoiceDirection $direction): string
    {
        return $direction === InvoiceDirection::Acquisto ? 'costo' : 'ricavo';
    }

    /**
     * @param array{direction?: string|null, receipt_date?: string|null, registration_date: string, year: int, period: int,
     *              lines: list<array{account_id: int, deductible_pct?: string|null}>, acknowledged?: list<string>, notes?: string|null} $data
     */
    /**
     * Prepara le righe di registro e verifica tutti i dati inseriti, senza salvare (ticket 05: stesse righe per anteprima e registrazione).
     * Righe inserite: [{summary?, account_id, deductible_pct, taxable?, tax?}]. Senza «summary» la riga i vale per il riepilogo i con l'importo pieno;
     * con «summary» un gruppo IVA può essere ripartito su più conti (ticket 04), gli importi parziali devono tornare al centesimo.
     * Il segno (nota di credito TD04: −1, importi in valore assoluto) viene determinato qui esattamente una volta.
     *
     * @return array{direction: InvoiceDirection, year: int, period: int, periodicity: string, period_label: string, registration_date: string, receipt_date: ?string, sign: int, acknowledged: list<string>, lines: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function prepare(Company $company, Invoice $invoice, array $data, bool $forEdit = false): array
    {
        $invoice->loadMissing('vatSummaries');
        ['blockers' => $blockers, 'acknowledge' => $toAck] = $this->checks($company, $invoice);
        if ($forEdit) {
            $blockers = array_values(array_filter($blockers, fn ($b) => $b !== 'La fattura non è da registrare.'));
        }
        if ($blockers) {
            RuleViolation::fail(['registration' => $blockers[0]]);
        }

        $errors = [];
        $direction = $invoice->direction ?? InvoiceDirection::tryFrom((string) ($data['direction'] ?? ''));
        if (! $direction) {
            $errors['direction'] = 'Indica se è un acquisto o una vendita.';
        }
        $docDate = $invoice->doc_date->toDateString();
        $today = now()->toDateString();
        $regDate = (string) ($data['registration_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $regDate) || $regDate < $docDate || $regDate > $today) {
            $errors['registration_date'] = 'La data di registrazione deve essere tra la data del documento e oggi.';
        }
        $receipt = $data['receipt_date'] ?? null;
        if ($direction === InvoiceDirection::Acquisto) {
            if (! $receipt || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $receipt) || $receipt < $docDate || $receipt > $today) {
                $errors['receipt_date'] = 'Per gli acquisti indica la data di ricezione (tra la data del documento e oggi).';
            }
        } else {
            $receipt = null;
        }

        $year = (int) ($data['year'] ?? 0);
        $period = (int) ($data['period'] ?? 0);
        $periodicity = '';
        $profile = $year ? $company->activeVatProfile($year) : null;
        if (! $year || ! $period) {
            $errors['period'] = 'Seleziona il periodo IVA.';
        } elseif (! $profile) {
            $errors['period'] = "Nessun regime IVA confermato per il {$year}: confermalo prima di registrare.";
        } else {
            $periodicity = $profile->periodicity->value;
            if ($period < 1 || $period > VatPeriod::max($periodicity)) {
                $errors['period'] = 'Periodo non valido per la periodicità '.$profile->periodicity->label().'.';
            } elseif (VatPeriod::end($year, $period, $periodicity) < $docDate) {
                $errors['period'] = 'Il periodo IVA non può essere precedente alla data del documento.';
            }
        }

        if (array_diff($toAck, $data['acknowledged'] ?? [])) {
            $errors['acknowledged'] = 'Conferma di aver verificato tutte le segnalazioni.';
        }

        $sign = $invoice->doc_type === 'TD04' ? -1 : 1;
        $summaries = $invoice->vatSummaries->values();
        // Integrazione IVA / Reverse charge (incarico di sviluppo 19.11.2026, sezione 5): gruppi integrati con aliquota italiana
        // e imposta calcolata; base = imponibile del gruppo. Nel Registro vendite come IVA a debito.
        $integrated = [];
        foreach ((array) ($data['integration'] ?? []) as $g => $it) {
            $g = (int) $g;
            if (! isset($summaries[$g])) {
                $errors["integration.{$g}"] = 'Gruppo IVA non valido.';

                continue;
            }
            $rate = Decimal::normalize(str_replace(',', '.', (string) ($it['rate'] ?? '')));
            if (! in_array($rate, ['4.00', '5.00', '10.00', '22.00'], true)) {
                $errors["integration.{$g}"] = 'Aliquota italiana non valida (4, 5, 10 o 22%).';

                continue;
            }
            $base = abs(Decimal::toMinor((string) $summaries[$g]->taxable));
            $orig = $summaries[$g];
            $summaries[$g] = (object) ['rate' => $rate, 'natura' => null, 'taxable' => Decimal::fromMinor($base), 'tax' => Decimal::fromMinor(self::percentOf($base, Decimal::toMinor($rate))),
                'original_natura' => $orig->natura];
            $integrated[$g] = true;
        }
        $inputLines = array_values($data['lines'] ?? []);
        $split = collect($inputLines)->contains(fn ($l) => array_key_exists('summary', (array) $l));
        if (! $split) {
            // Una riga per riepilogo con importo pieno (formato precedente).
            if (count($inputLines) !== $summaries->count()) {
                $errors['lines'] = 'Assegna un conto a ogni riga del riepilogo IVA.';
            }
            $inputLines = array_map(fn ($l, $i) => [...(array) $l, 'summary' => $i], $inputLines, array_keys($inputLines));
        }

        $accounts = $company->accounts()->whereIn('id', array_map(fn ($l) => (int) ($l['account_id'] ?? 0), $inputLines))->get()->keyBy('id');
        $lines = [];
        $byGroup = [];
        foreach ($inputLines as $k => $line) {
            $g = (int) ($line['summary'] ?? -1);
            if (! isset($summaries[$g])) {
                $errors["lines.{$k}.summary"] = 'Riga del riepilogo IVA non valida.';

                continue;
            }
            $byGroup[$g][] = $k;
            $acc = $accounts[(int) ($line['account_id'] ?? 0)] ?? null;
            if (! $acc || ! $acc->active) {
                $errors["lines.{$k}.account_id"] = 'Scegli un conto attivo del piano dei conti.';
            } elseif (! $acc->iva_rilevante) {
                $errors["lines.{$k}.account_id"] = "Il conto {$acc->code} è fuori campo IVA: scegli un conto rilevante ai fini IVA.";
            } elseif ($direction && $acc->type !== self::accountType($direction) && ! ($acc->type === 'patrimoniale' && $acc->iva_rilevante)) {
                $errors["lines.{$k}.account_id"] = $direction === InvoiceDirection::Acquisto ? 'Per un acquisto scegli un conto di costo.' : 'Per una vendita scegli un conto di ricavo.';
            }
            // Ticket 03: campo vuoto = valore mancante, solo 0–100.
            $pctRaw = trim((string) ($line['deductible_pct'] ?? ''));
            if ($direction === InvoiceDirection::Acquisto && ! self::validPct($pctRaw)) {
                $errors["lines.{$k}.deductible_pct"] = $pctRaw === '' ? 'Indica la percentuale detraibile (0–100).' : 'Percentuale detraibile tra 0 e 100.';
            }
            $lines[$k] = ['summary' => $g, 'account' => $acc, 'pct' => $pctRaw];
        }
        foreach ($summaries as $g => $s) {
            if (empty($byGroup[$g])) {
                $errors['lines'] = 'Assegna un conto a ogni riga del riepilogo IVA.';
            }
        }

        // Importi per riga parziale in centesimi; la somma per gruppo deve corrispondere esattamente al gruppo (ticket 04).
        $amount = function ($v) {
            $v = str_replace(',', '.', trim((string) $v));

            return preg_match('/^-?\d{1,12}(\.\d{1,2})?$/', $v) ? Decimal::toMinor($v) : null;
        };
        foreach ($byGroup as $g => $ks) {
            $s = $summaries[$g];
            $gt = Decimal::toMinor((string) $s->taxable);
            $gv = Decimal::toMinor((string) $s->tax);
            if ($sign === -1) {
                $gt = abs($gt);
                $gv = abs($gv);
            }
            if (count($ks) === 1 && ! isset($inputLines[$ks[0]]['taxable']) && ! isset($inputLines[$ks[0]]['tax'])) {
                $lines[$ks[0]] += ['taxable' => $gt, 'tax' => $gv];

                continue;
            }
            $sumT = $sumV = 0;
            foreach ($ks as $k) {
                $t = $amount($inputLines[$k]['taxable'] ?? null);
                $v = $amount($inputLines[$k]['tax'] ?? null);
                if ($t === null) {
                    $errors["lines.{$k}.taxable"] = 'Importo non valido.';
                }
                if ($v === null) {
                    $errors["lines.{$k}.tax"] = 'Importo non valido.';
                }
                $lines[$k] += ['taxable' => $t ?? 0, 'tax' => $v ?? 0];
                $sumT += $t ?? 0;
                $sumV += $v ?? 0;
            }
            if ($sumT !== $gt || $sumV !== $gv) {
                $errors["groups.{$g}"] = 'Residuo da attribuire: imponibile '.self::it($gt - $sumT).', imposta '.self::it($gv - $sumV).'. La ripartizione deve corrispondere esattamente al riepilogo.';
            }
        }
        if ($errors) {
            RuleViolation::fail($errors);
        }

        $out = [];
        $tot = ['taxable' => 0, 'tax' => 0, 'deductible' => 0, 'non_deductible' => 0];
        foreach ($lines as $k => $l) {
            $s = $summaries[$l['summary']];
            $pct = $direction === InvoiceDirection::Acquisto ? str_replace(',', '.', $l['pct']) : '0';
            $d = $direction === InvoiceDirection::Acquisto ? self::percentOf($l['tax'], Decimal::toMinor($pct, 2)) : 0;
            $out[] = [
                'summary' => $l['summary'], 'account_id' => $l['account']->id, 'account_label' => $l['account']->code.' · '.$l['account']->name,
                'rate' => (string) $s->rate, 'natura' => $s->natura, 'taxable' => $l['taxable'], 'tax' => $l['tax'], 'integrated' => isset($integrated[$l['summary']]),
                'deductible_pct' => Decimal::normalize($pct), 'deductible' => $d, 'non_deductible' => $direction === InvoiceDirection::Acquisto ? $l['tax'] - $d : 0,
            ];
            $tot['taxable'] += $l['taxable'];
            $tot['tax'] += $l['tax'];
            $tot['deductible'] += $d;
            $tot['non_deductible'] += $direction === InvoiceDirection::Acquisto ? $l['tax'] - $d : 0;
        }

        return [
            'direction' => $direction, 'year' => $year, 'period' => $period, 'periodicity' => $periodicity,
            'period_label' => VatPeriod::label($year, $period, $periodicity), 'registration_date' => $regDate, 'receipt_date' => $receipt,
            'sign' => $sign, 'acknowledged' => $toAck, 'lines' => $out, 'totals' => $tot,
        ];
    }

    /** Importo in centesimi come testo italiano (messaggi di errore). */
    private static function it(int $cents): string
    {
        return str_replace('.', ',', Decimal::fromMinor($cents));
    }

    /**
     * Registra una fattura. Con «request_id» il tentativo è idempotente (ticket 06): stesso ID e stesso contenuto restituiscono
     * di nuovo la registrazione salvata, stesso ID con contenuto diverso dà un conflitto senza seconda registrazione.
     *
     * @param  array{direction: string, year: int, protocol: int}|null  $keep  Protocollo di una registrazione modificata: resta se registro e anno sono uguali
     */
    public function register(Company $company, Invoice $invoice, User $actor, array $data, ?array $keep = null): VatRegistration
    {
        return DB::transaction(function () use ($company, $invoice, $actor, $data, $keep) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $requestId = $keep ? null : (($data['request_id'] ?? '') ?: null);
            $hash = hash('sha256', json_encode(collect($data)->except(['request_id', 'next'])->sortKeys()->all()));
            if ($requestId && ($prev = RegistrationRequest::where('company_id', $company->id)->where('request_id', $requestId)->first())) {
                if ($prev->payload_hash !== $hash || $prev->invoice_id !== $invoice->id) {
                    throw new RegistrationConflict('Questo invio (stessa richiesta) è già stato usato con dati diversi: nessuna seconda registrazione. Ricarica la pagina e verifica l’esito.');
                }
                $done = $prev->registration_id ? VatRegistration::find($prev->registration_id) : null;
                if (! $done) {
                    throw new RegistrationConflict('La registrazione di questo invio non esiste più (è stata eliminata). Ricarica la pagina.');
                }
                $done->replayed = true;

                return $done;
            }

            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($existing = $invoice->activeRegistration()) {
                // Secondo utente o seconda sessione: esattamente una registrazione attiva, quella esistente viene indicata.
                throw new RegistrationConflict("La fattura è già registrata con protocollo {$existing->protocol}/{$existing->year}.", $existing);
            }
            $prep = $this->prepare($company, $invoice, $data);
            $direction = $prep['direction'];
            $year = $prep['year'];
            // Dopo uno storno: collegare la nuova registrazione a quella stornata (ticket 06).
            $replaces = VatRegistration::where('invoice_id', $invoice->id)->where('status', 'stornata')->orderByDesc('id')->value('id');

            $registration = VatRegistration::create([
                'company_id' => $company->id, 'invoice_id' => $invoice->id, 'active_invoice_id' => $invoice->id,
                'direction' => $direction, 'year' => $year, 'period' => $prep['period'], 'periodicity' => $prep['periodicity'],
                'protocol' => $keep && $keep['direction'] === $direction->value && $keep['year'] === $year ? $keep['protocol'] : $this->nextProtocol($company, $direction, $year),
                'registration_date' => $prep['registration_date'], 'receipt_date' => $prep['receipt_date'], 'sign' => $prep['sign'],
                'taxable_total' => Decimal::fromMinor($prep['totals']['taxable']), 'tax_total' => Decimal::fromMinor($prep['totals']['tax']), 'deductible_total' => Decimal::fromMinor($prep['totals']['deductible']),
                'status' => 'registrata', 'acknowledged' => $prep['acknowledged'] ?: null, 'notes' => $data['notes'] ?? null,
                'rule_version' => config('semplicon.rule_version'), 'created_by' => $actor->id,
                'document_id' => $invoice->document_id, // il documento (file importato o allegato) appartiene sempre alla registrazione
                'replaces_id' => $replaces,
                'integration_id' => $data['integration_id'] ?? null,
            ]);
            $registration->lines()->createMany(array_map(fn ($l) => [
                'account_id' => $l['account_id'], 'rate' => $l['rate'], 'natura' => $l['natura'], 'summary_index' => $l['summary'], 'integrated' => $l['integrated'] ?? false,
                'taxable' => Decimal::fromMinor($l['taxable']), 'tax' => Decimal::fromMinor($l['tax']),
                'deductible_pct' => $l['deductible_pct'], 'deductible_tax' => Decimal::fromMinor($l['deductible']),
            ], $prep['lines']));
            $invoice->update(['status' => InvoiceStatus::Registrata, 'direction' => $direction] + ($prep['receipt_date'] ? ['receipt_date' => $prep['receipt_date']] : []));
            if ($requestId) {
                RegistrationRequest::create(['company_id' => $company->id, 'request_id' => $requestId, 'invoice_id' => $invoice->id, 'payload_hash' => $hash,
                    'registration_id' => $registration->id, 'created_by' => $actor->id]);
            }

            if (! $keep && ! empty($data['rules'])) {
                app(AccountRuleService::class)->remember($company, $invoice->fresh(), $actor, $data['rules']); // ticket 09
            }
            if ($keep) {
                return $registration; // lo registra nel protocollo il chiamante come registration.updated
            }
            app(FiscalService::class)->sync($invoice);
            app(SettlementService::class)->touch($company, $registration->year, $registration->period, "Nuova registrazione {$registration->protocol}/{$registration->year} nel periodo.");
            Audit::record('registration.created', $actor, $company, $registration, data: [
                'invoice' => $invoice->id, 'protocol' => $registration->protocol, 'direction' => $direction->value,
                'period' => $prep['period_label'], 'request_id' => $requestId, 'replaces' => $replaces,
            ]);

            return $registration;
        });
    }

    /**
     * Modifica una registrazione valida (dati, periodo, conti, % detraibile). Le verifiche sono le stesse della
     * registrazione; il protocollo resta se registro e anno sono uguali, altrimenti segue la numerazione del nuovo anno.
     * Indicazione del committente, 19.11.2026. Lo stato precedente è nel registro delle attività.
     */
    public function update(Company $company, VatRegistration $registration, User $actor, array $data): VatRegistration
    {
        if ($registration->status !== 'registrata') {
            RuleViolation::fail(['registration' => 'Si può modificare solo una registrazione valida (non stornata e non uno storno).']);
        }

        return DB::transaction(function () use ($company, $registration, $actor, $data) {
            $before = self::snapshot($registration);
            $invoice = $registration->invoice;
            $this->remove($registration, $invoice);
            $new = $this->register($company, $invoice->fresh(), $actor, $data,
                ['direction' => $registration->direction->value, 'year' => $registration->year, 'protocol' => $registration->protocol]);
            app(FiscalService::class)->sync($invoice);
            foreach (array_unique([[$registration->year, $registration->period], [$new->year, $new->period]], SORT_REGULAR) as [$y, $p]) {
                app(SettlementService::class)->touch($company, $y, $p, "Registrazione {$new->protocol}/{$new->year} modificata.");
            }
            Audit::record('registration.updated', $actor, $company, $new, data: ['before' => $before, 'after' => self::snapshot($new)]);

            return $new;
        });
    }

    /** Elimina una registrazione valida; la fattura torna poi «da registrare». Indicazione del committente, 19.11.2026. */
    public function delete(Company $company, VatRegistration $registration, User $actor): void
    {
        if ($registration->status !== 'registrata') {
            RuleViolation::fail(['registration' => 'Si può eliminare solo una registrazione valida. Storni e registrazioni stornate si eliminano solo insieme alla fattura.']);
        }
        DB::transaction(function () use ($company, $registration, $actor) {
            $before = self::snapshot($registration);
            $this->remove($registration, $registration->invoice);
            app(FiscalService::class)->sync($registration->invoice);
            app(SettlementService::class)->touch($company, $registration->year, $registration->period, "Registrazione {$registration->protocol}/{$registration->year} eliminata.");
            Audit::record('registration.deleted', $actor, $company, $registration->invoice, data: ['before' => $before]);
        });
    }

    private function remove(VatRegistration $registration, Invoice $invoice): void
    {
        VatRegistration::unlocked(function () use ($registration) {
            $registration->lines()->delete();
            $registration->delete();
        });
        $invoice->update(['status' => ($invoice->issues ?? []) ? InvoiceStatus::DaVerificare : InvoiceStatus::Importata]);
    }

    /** Stato di una registrazione per il registro delle attività. */
    public static function snapshot(VatRegistration $r): array
    {
        $r->loadMissing('lines.account');

        return [
            'protocol' => "{$r->protocol}/{$r->year}", 'direction' => $r->direction->value, 'period' => VatPeriod::label($r->year, $r->period, $r->periodicity),
            'registration_date' => $r->registration_date->toDateString(), 'receipt_date' => $r->receipt_date?->toDateString(), 'status' => $r->status,
            'taxable' => (string) $r->taxable_total, 'tax' => (string) $r->tax_total, 'deductible' => (string) $r->deductible_total,
            'lines' => $r->lines->map(fn ($l) => ['account' => $l->account?->code, 'rate' => (string) $l->rate, 'natura' => $l->natura, 'taxable' => (string) $l->taxable, 'tax' => (string) $l->tax, 'pct' => (string) $l->deductible_pct])->all(),
        ];
    }

    public function reverse(Company $company, VatRegistration $registration, User $actor, string $reason, ?string $date = null): VatRegistration
    {
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo dello storno.']);
        }
        $date ??= now()->toDateString();

        return DB::transaction(function () use ($company, $registration, $actor, $reason, $date) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $original = VatRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();
            if ($original->status !== 'registrata') {
                RuleViolation::fail(['registration' => 'Questa registrazione è già stornata.']);
            }
            $original->load('lines');

            $origDate = $original->registration_date->toDateString();
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $origDate || $date > now()->toDateString()) {
                RuleViolation::fail(['date' => 'La data dello storno deve essere tra la data della registrazione ('.implode('/', array_reverse(explode('-', $origDate))).') e oggi.']);
            }
            $year = (int) substr($date, 0, 4);
            $profile = $company->activeVatProfile($year);
            if (! $profile) {
                RuleViolation::fail(['date' => "Nessun regime IVA confermato per il {$year}: confermalo prima dello storno."]);
            }
            $periodicity = $profile->periodicity->value;
            $period = VatPeriod::ofDate($date, $periodicity)['period'];

            $storno = VatRegistration::create([
                ...collect($original->getAttributes())->except(['id', 'created_at', 'updated_at', 'active_invoice_id', 'acknowledged', 'notes', 'status', 'sign', 'protocol', 'registration_date', 'created_by', 'reverses_id', 'rule_version', 'year', 'period', 'periodicity'])->all(),
                'year' => $year, 'period' => $period, 'periodicity' => $periodicity,
                'sign' => -$original->sign, 'status' => 'storno', 'reverses_id' => $original->id, 'active_invoice_id' => null,
                'protocol' => $this->nextProtocol($company, $original->direction, $year),
                'registration_date' => $date, 'notes' => $reason,
                'rule_version' => config('semplicon.rule_version'), 'created_by' => $actor->id,
            ]);
            $storno->lines()->createMany($original->lines->map(fn ($l) => collect($l->getAttributes())->except(['id', 'vat_registration_id'])->all())->all());
            $original->update(['status' => 'stornata', 'active_invoice_id' => null]);

            $invoice = $original->invoice;
            $invoice->update(['status' => $invoice->issues ? InvoiceStatus::DaVerificare : InvoiceStatus::Importata]);
            app(FiscalService::class)->sync($original->invoice);
            foreach (array_unique([[$original->year, $original->period], [$storno->year, $storno->period]], SORT_REGULAR) as [$y, $p]) {
                app(SettlementService::class)->touch($company, $y, $p, "Storno della registrazione {$original->protocol}/{$original->year}: {$reason}");
            }
            Audit::record('registration.reversed', $actor, $company, $original, $reason, [
                'storno_protocol' => $storno->protocol, 'storno_period' => VatPeriod::label($year, $period, $periodicity),
            ]);

            return $storno;
        });
    }

    public function discard(Company $company, Invoice $invoice, User $actor, string $reason): void
    {
        if (! $invoice->status->isOpen()) {
            RuleViolation::fail(['registration' => 'Si possono scartare solo fatture non registrate.']);
        }
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo.']);
        }
        $invoice->update(['status' => InvoiceStatus::Scartata, 'discard_reason' => $reason]);
        Audit::record('invoice.discarded', $actor, $company, $invoice, $reason);
    }

    public function restore(Company $company, Invoice $invoice, User $actor): void
    {
        if ($invoice->status !== InvoiceStatus::Scartata) {
            RuleViolation::fail(['registration' => 'La fattura non è scartata.']);
        }
        $invoice->update(['status' => $invoice->issues ? InvoiceStatus::DaVerificare : InvoiceStatus::Importata, 'discard_reason' => null]);
        Audit::record('invoice.restored', $actor, $company, $invoice);
    }

    /** Prossima fattura aperta per la modalità di controllo. */
    public function nextOpen(Company $company, ?int $afterId = null): ?Invoice
    {
        return $company->invoices()->whereIn('status', [InvoiceStatus::Importata, InvoiceStatus::DaVerificare])
            ->when($afterId, fn ($q) => $q->where('id', '!=', $afterId))
            ->orderBy('doc_date')->orderBy('id')->first();
    }

    private function nextProtocol(Company $company, InvoiceDirection $direction, int $year): int
    {
        return (int) VatRegistration::where('company_id', $company->id)->where('direction', $direction)->where('year', $year)->max('protocol') + 1;
    }

    public static function validPct(string $v): bool
    {
        $v = str_replace(',', '.', trim($v));
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $v, $m)) {
            return false;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0') <= 10000;
    }

    /** Quota in centesimi: $cents * $pctHundredths / 10000, arrotondata commercialmente (mezzo centesimo in valore assoluto). */
    public static function percentOf(int $cents, int $pctHundredths): int
    {
        $x = abs($cents) * $pctHundredths;
        $r = intdiv($x + 5000, 10000);

        return $cents < 0 ? -$r : $r;
    }
}
