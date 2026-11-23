<?php

namespace App\Domain\Esterometro;

use App\Domain\Audit;
use App\Domain\RegistrationService;
use App\Domain\RuleViolation;
use App\Enums\InvoiceDirection;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceIntegration;
use App\Models\User;
use App\Models\VatRegistration;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * «Integra IVA / Reverse charge» per le fatture di acquisto (incarico di sviluppo 19.11.2026, sezione 5).
 * L'integrazione è collegata alla fattura originale: costi, conti e pagamenti restano lì; i gruppi IVA integrati
 * compaiono nel Registro acquisti (con IVA detraibile) e nel Registro vendite (IVA a debito) e confluiscono nella liquidazione.
 * Nessuna registrazione di ricavo, nessun credito verso il cliente, nessuna scadenza.
 */
final class IntegrationService
{
    public const TYPES = ['TD16' => 'Integrazione reverse charge interno', 'TD17' => 'Integrazione/autofattura servizi dall’estero',
        'TD18' => 'Integrazione acquisto beni intracomunitari', 'TD19' => 'Integrazione/autofattura beni ex art. 17 c. 2 DPR 633/72'];

    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'EL', 'HR', 'HU', 'IE', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public const KINDS = ['servizi' => 'Servizi', 'beni_ue' => 'Beni da paese UE', 'beni_17c2' => 'Beni già in Italia (art. 17 c. 2)', 'beni_extra_ue' => 'Beni da paese extra UE (importazione)'];

    public static function country(Invoice $i, string $side = 'supplier'): string
    {
        $c = $i->{$side.'_country'} ?: (preg_match('/^([A-Z]{2})/', (string) $i->{$side.'_vat'}, $m) ? $m[1] : null);

        return strtoupper($c ?: 'IT');
    }

    /** Suggerimento da operazione, territorialità, tipo di prestazione, dati del fornitore e natura. */
    public function propose(Company $company, Invoice $invoice): array
    {
        $invoice->loadMissing('vatSummaries');
        $out = ['possible' => false, 'doc_type' => null, 'reason' => '', 'groups' => [], 'country' => self::country($invoice), 'kind' => $invoice->xb_kind, 'warnings' => []];
        if ($invoice->direction !== InvoiceDirection::Acquisto) {
            $out['reason'] = 'Solo per le fatture di acquisto: una fattura di vendita emessa in reverse charge non genera integrazione.';

            return $out;
        }
        $country = $out['country'];
        $foreign = $country !== 'IT';
        $kind = $invoice->xb_kind ?? ($foreign ? (in_array($country, self::EU, true) ? 'servizi' : 'servizi') : null);
        $out['kind'] = $kind;
        if ($foreign && $invoice->xb_relevant === false) {
            $out['reason'] = 'Operazione non rilevante territorialmente in Italia: nessuna IVA italiana. Se va comunicata, l’XML con natura N2.2 si crea da Operazioni transfrontaliere.';

            return $out;
        }
        if ($foreign && $kind === 'beni_extra_ue' && $invoice->xb_customs) {
            $out['reason'] = 'Importazione con bolletta doganale: l’IVA è assolta in dogana, nessuna integrazione.';

            return $out;
        }
        $docType = ! $foreign ? 'TD16' : match ($kind) {
            'beni_ue' => 'TD18', 'beni_17c2', 'beni_extra_ue' => 'TD19', default => 'TD17',
        };
        $year = (int) (($invoice->receipt_date ?? $invoice->doc_date)->format('Y'));
        $forfettario = (bool) $company->activeVatProfile($year)?->forfettario_iva;
        foreach ($invoice->vatSummaries->values() as $k => $s) {
            $candidate = ! $foreign ? str_starts_with((string) $s->natura, 'N6') : Decimal::toMinor((string) $s->tax) === 0;
            if ($candidate) {
                $out['groups'][] = ['summary_index' => $k, 'natura' => $s->natura, 'base' => Decimal::normalize((string) $s->taxable),
                    'rate' => '22.00', 'deductible_pct' => $forfettario ? '0' : '100'];
            }
        }
        if (! $out['groups']) {
            $out['reason'] = $foreign ? 'Nessun gruppo senza IVA italiana da integrare.' : 'Nessuna riga in reverse charge (natura N6.x): per una fattura italiana con IVA non serve integrazione.';

            return $out;
        }
        $out['possible'] = true;
        $out['doc_type'] = $docType;
        $out['reason'] = match ($docType) {
            'TD16' => 'Fornitore italiano con natura N6 (inversione contabile): integrazione TD16 (art. 17 c. 5–6 DPR 633/72).',
            'TD17' => "Fornitore {$country}".(in_array($country, self::EU, true) ? ' (UE)' : ' (extra UE)').', servizi: autofattura/integrazione TD17 (art. 7-ter e 17 c. 2 DPR 633/72).',
            'TD18' => "Fornitore {$country} (UE), beni: integrazione TD18 (art. 46–47 DL 331/93).",
            default => "Fornitore {$country}, beni già in Italia: autofattura TD19 (art. 17 c. 2 DPR 633/72).",
        };
        if ($forfettario) {
            $out['warnings'][] = 'Regime forfettario: l’IVA integrata si versa senza detrazione (% detraibile 0).';
        }
        if ($foreign && ! $invoice->xb_kind) {
            $out['warnings'][] = 'Natura della prestazione presunta: “Servizi”. Indica “Beni” se si tratta di merci.';
        }

        return $out;
    }

    /**
     * Confermare l'integrazione: creare il documento di integrazione e contabilizzare la registrazione (nuova o esistente) con i gruppi integrati.
     *
     * @param  array{doc_type: string, doc_date: string, kind?: ?string, receipt_date: string, registration_date: string, year: int, period: int, groups: list<array{summary_index: int, integrate?: bool, rate?: string, account_id: int, deductible_pct: string}>, request_id?: ?string}  $d
     */
    public function integrate(Company $company, Invoice $invoice, User $actor, array $d): InvoiceIntegration
    {
        return DB::transaction(function () use ($company, $invoice, $actor, $d) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $invoice = Invoice::whereKey($invoice->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $e = [];
            if ($invoice->direction !== InvoiceDirection::Acquisto) {
                $e['doc_type'] = 'L’integrazione IVA riguarda solo le fatture di acquisto.';
            }
            if (! array_key_exists($d['doc_type'] ?? '', self::TYPES)) {
                $e['doc_type'] = 'Scegli il tipo di integrazione (TD16, TD17, TD18 o TD19).';
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['doc_date'] ?? '')) || $d['doc_date'] < $invoice->doc_date->toDateString()) {
                $e['doc_date'] = 'Data dell’integrazione non valida (non prima della fattura).';
            }
            $integrate = [];
            $lines = [];
            foreach (array_values($d['groups'] ?? []) as $g) {
                $k = (int) $g['summary_index'];
                if (! empty($g['integrate'])) {
                    $integrate[$k] = ['rate' => (string) ($g['rate'] ?? '22')];
                }
                $lines[$k] = ['account_id' => (int) ($g['account_id'] ?? 0), 'deductible_pct' => (string) ($g['deductible_pct'] ?? '')];
            }
            if (! $integrate) {
                $e['groups'] = 'Seleziona almeno un gruppo IVA da integrare.';
            }
            if ($existing = InvoiceIntegration::where('invoice_id', $invoice->id)->latest('id')->first()) {
                if (VatRegistration::where('active_invoice_id', $invoice->id)->where('integration_id', $existing->id)->exists()) {
                    $e['doc_type'] = 'La fattura ha già un’integrazione registrata (n. '.$existing->number.'/'.$existing->year.'): per correggerla storna la registrazione.';
                }
            }
            if ($e) {
                RuleViolation::fail($e);
            }
            ksort($lines);
            $year = (int) substr($d['doc_date'], 0, 4);
            $number = (int) InvoiceIntegration::where('company_id', $company->id)->where('year', $year)->max('number') + 1;
            $sums = $invoice->vatSummaries()->get()->values();
            $groups = [];
            foreach ($integrate as $k => $it) {
                $base = abs(Decimal::toMinor((string) $sums[$k]->taxable));
                $rate = Decimal::normalize(str_replace(',', '.', $it['rate']));
                $tax = RegistrationService::percentOf($base, Decimal::toMinor($rate));
                $pct = str_replace(',', '.', $lines[$k]['deductible_pct'] ?: '0');
                $ded = RegistrationService::percentOf($tax, Decimal::toMinor(Decimal::normalize($pct)));
                $groups[] = ['summary_index' => $k, 'natura' => $sums[$k]->natura, 'base' => Decimal::fromMinor($base), 'rate' => $rate, 'tax' => Decimal::fromMinor($tax),
                    'deductible_pct' => Decimal::normalize($pct), 'deductible' => Decimal::fromMinor($ded), 'nondeductible' => Decimal::fromMinor($tax - $ded)];
            }
            $int = InvoiceIntegration::create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'doc_type' => $d['doc_type'], 'year' => $year, 'number' => $number,
                'doc_date' => $d['doc_date'], 'groups' => $groups, 'reason' => ($d['reason'] ?? null) ?: null, 'created_by' => $actor->id]);
            if (! empty($d['kind']) && array_key_exists($d['kind'], self::KINDS)) {
                $invoice->update(['xb_kind' => $d['kind'], 'xb_relevant' => true]);
            }
            $reg = ['receipt_date' => $d['receipt_date'], 'registration_date' => $d['registration_date'], 'year' => (int) $d['year'], 'period' => (int) $d['period'],
                'lines' => array_values($lines), 'acknowledged' => $d['acknowledged'] ?? ['*'], 'integration' => $integrate, 'integration_id' => $int->id];
            $svc = app(RegistrationService::class);
            if ($active = $invoice->activeRegistration()) {
                $svc->update($company, $active, $actor, $reg + ['acknowledged' => array_merge($active->acknowledged ?? [], $reg['acknowledged'])]);
            } else {
                $svc->register($company, $invoice, $actor, $reg);
            }
            Audit::record('integration.created', $actor, $company, $invoice, data: ['doc_type' => $int->doc_type, 'number' => "{$int->number}/{$int->year}",
                'tax' => Decimal::fromMinor(array_sum(array_map(fn ($g) => Decimal::toMinor($g['tax']), $groups))), 'deductible' => Decimal::fromMinor(array_sum(array_map(fn ($g) => Decimal::toMinor($g['deductible']), $groups)))]);

            return $int;
        });
    }

    /** Associare i TD16–TD19 importati a un'integrazione manuale: candidati (stesso fornitore, tipo e base). */
    public static function matches(Company $company, array $parsed, array $body)
    {
        $base = Decimal::toMinor($body['taxable_total']);
        $supVat = $parsed['supplier']['vat'];
        $supName = mb_strtolower(trim((string) $parsed['supplier']['name']));

        return InvoiceIntegration::where('company_id', $company->id)->where('doc_type', $body['doc_type'])->whereNull('document_id')->with('invoice')->get()
            ->filter(fn ($i) => ($supVat ? $i->invoice->supplier_vat === $supVat : mb_strtolower(trim($i->invoice->supplier_name)) === $supName)
                && array_sum(array_map(fn ($g) => Decimal::toMinor($g['base']), $i->groups)) === abs($base))->values();
    }
}
