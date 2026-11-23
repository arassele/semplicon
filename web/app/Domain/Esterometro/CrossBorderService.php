<?php

namespace App\Domain\Esterometro;

use App\Domain\Audit;
use App\Domain\RuleViolation;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceIntegration;
use App\Models\User;
use App\Models\XmlExport;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * IVA › Operazioni transfrontaliere (sezione 7): lista di controllo delle operazioni transfrontaliere con termine, motivo di esclusione
 * e stato XML. Sostituisce il menu Spesometro; nessuna DTE/DTR, nessuna ricevuta SdI. Generare e scaricare non registrano nulla.
 *
 * Termini: passive (TD17/18/19) entro il 15 del mese successivo al ricevimento (in subordine data del documento); attive secondo il termine di emissione
 * della fattura (ipotesi: data del documento + 12 giorni, art. 21 c. 4 DPR 633/72).
 * Esclusioni: bolletta doganale; già emesse o ricevute tramite SdI; acquisti non rilevanti fino a 5.000 € per operazione
 * (solo questa categoria, art. 1 c. 3-bis D.Lgs. 127/2015).
 */
final class CrossBorderService
{
    public const NON_RELEVANT_THRESHOLD = 500000; // centesimi

    public static function deadline(Invoice $i): array
    {
        if ($i->direction === InvoiceDirection::Acquisto) {
            $trigger = CarbonImmutable::parse(($i->receipt_date ?? $i->doc_date)->toDateString());

            return ['trigger' => $trigger->toDateString(), 'trigger_label' => $i->receipt_date ? 'ricezione' : 'data operazione',
                'deadline' => $trigger->startOfMonth()->addMonth()->day(15)->toDateString(), 'rule' => '15 del mese successivo alla ricezione'];
        }
        $trigger = CarbonImmutable::parse($i->doc_date->toDateString());

        return ['trigger' => $trigger->toDateString(), 'trigger_label' => 'data fattura', 'deadline' => $trigger->addDays(12)->toDateString(), 'rule' => 'termine di emissione (12 giorni)'];
    }

    /** Fatture transfrontaliere dell'azienda (controparte estera), eventualmente per anno della data determinante. */
    public function rows(Company $company, ?int $year): array
    {
        $invoices = $company->invoices()->whereNotIn('status', [InvoiceStatus::Archiviata, InvoiceStatus::Scartata])->whereNotNull('direction')
            ->with('vatSummaries')->orderBy('doc_date')->get()
            ->filter(fn (Invoice $i) => IntegrationService::country($i, $i->direction === InvoiceDirection::Acquisto ? 'supplier' : 'customer') !== 'IT'
                && ! in_array($i->doc_type, ['TD16', 'TD17', 'TD18', 'TD19'], true));
        $ints = InvoiceIntegration::where('company_id', $company->id)->whereIn('invoice_id', $invoices->pluck('id'))->orderByDesc('id')->get()->groupBy('invoice_id');
        $exports = XmlExport::where('company_id', $company->id)->whereIn('invoice_id', $invoices->pluck('id'))->orderByDesc('version')->get()->groupBy('invoice_id');
        $rows = [];
        foreach ($invoices as $i) {
            $d = self::deadline($i);
            if ($year && (int) substr($d['trigger'], 0, 4) !== $year) {
                continue;
            }
            $rows[] = $this->row($i, $d, $ints[$i->id] ?? collect(), $exports[$i->id] ?? collect());
        }

        return $rows;
    }

    private function row(Invoice $i, array $d, $ints, $exports): array
    {
        $purchase = $i->direction === InvoiceDirection::Acquisto;
        $reg = $i->activeRegistration();
        $int = $ints->first(fn ($x) => $reg?->integration_id === $x->id);
        $total = abs(Decimal::toMinor((string) $i->taxable_total));
        $exclusion = $this->exclusion($i, $total);
        if ($purchase) {
            $docType = $int?->doc_type ?? match ($i->xb_kind) {
                'beni_ue' => 'TD18', 'beni_17c2', 'beni_extra_ue' => 'TD19', default => 'TD17'
            };
            $state = $exclusion ? 'escluso' : ($int ? 'integrata' : ($i->xb_relevant === false ? 'non_rilevante' : 'integrazione_mancante'));
        } else {
            $docType = $i->doc_type;
            $state = $exclusion ? 'escluso' : 'da_trasmettere';
        }
        $last = $exports->first();

        return [
            'id' => $i->id, 'direction' => $i->direction->value, 'number' => $i->number, 'doc_date' => $i->doc_date->toDateString(), 'doc_type' => $i->doc_type,
            'xml_doc_type' => $docType, 'partner' => $purchase ? $i->supplier_name : $i->customer_name, 'partner_vat' => $purchase ? $i->supplier_vat : $i->customer_vat,
            'country' => IntegrationService::country($i, $purchase ? 'supplier' : 'customer'), 'taxable' => Decimal::fromMinor($total), 'currency' => $i->currency,
            'kind' => $i->xb_kind, 'relevant' => $i->xb_relevant, 'customs' => (bool) $i->xb_customs, 'sdi' => (bool) $i->xb_sdi, 'source' => $i->source,
            'integration' => $int ? ['number' => "{$int->number}/{$int->year}", 'doc_type' => $int->doc_type, 'tax' => Decimal::fromMinor(array_sum(array_map(fn ($g) => Decimal::toMinor($g['tax']), $int->groups)))] : null,
            ...$d, 'overdue' => ! $exclusion && ! $last && $d['deadline'] < now()->toDateString(),
            'exclusion' => $exclusion, 'state' => $state,
            'xml' => $last ? ['status' => 'XML generato', 'version' => $last->version, 'filename' => $last->filename, 'generated_at' => $last->generated_at->toIso8601String(),
                'downloaded_at' => $last->downloaded_at?->toIso8601String(), 'downloads' => $last->downloads, 'id' => $last->id] : null,
        ];
    }

    public function exclusion(Invoice $i, int $taxableCents): ?string
    {
        if ($i->xb_customs) {
            return 'Bolletta doganale: operazione già documentata in dogana.';
        }
        if ($i->source === 'emessa') {
            return 'Fattura emessa con Semplicon (CodiceDestinatario XXXXXXX): trasmetti il file XML al SdI.';
        }
        if ($i->xb_sdi) {
            return $i->direction === InvoiceDirection::Acquisto ? 'Fattura ricevuta tramite SdI.' : 'Fattura già trasmessa tramite SdI.';
        }
        if ($i->direction === InvoiceDirection::Acquisto && $i->xb_relevant === false && $taxableCents <= self::NON_RELEVANT_THRESHOLD) {
            return 'Acquisto non rilevante in Italia fino a 5.000 € per operazione: comunicazione non obbligatoria.';
        }

        return null;
    }

    /** Classificazione (tipo, rilevanza, bolletta doganale): non modifica alcuna registrazione. */
    public function classify(Company $company, Invoice $invoice, User $actor, array $d): void
    {
        $e = [];
        if (isset($d['kind']) && $d['kind'] !== null && ! array_key_exists($d['kind'], IntegrationService::KINDS)) {
            $e['kind'] = 'Tipo di operazione non valido.';
        }
        if ($e) {
            RuleViolation::fail($e);
        }
        $before = ['kind' => $invoice->xb_kind, 'relevant' => $invoice->xb_relevant, 'customs' => $invoice->xb_customs];
        $invoice->update(['xb_kind' => $d['kind'] ?? null, 'xb_relevant' => $d['relevant'] ?? null, 'xb_customs' => (bool) ($d['customs'] ?? false)]);
        Audit::record('xml.classified', $actor, $company, $invoice, data: ['before' => $before, 'after' => ['kind' => $invoice->xb_kind, 'relevant' => $invoice->xb_relevant, 'customs' => $invoice->xb_customs]]);
    }

    /**
     * Generare l'XML: nuova versione solo se il contenuto cambia. Nessuna scrittura contabile, nessuna registrazione IVA.
     *
     * @return array{export: ?XmlExport, errors: list<array<string, string>>}
     */
    public function generate(Company $company, Invoice $invoice, User $actor): array
    {
        $total = abs(Decimal::toMinor((string) $invoice->taxable_total));
        if ($ex = $this->exclusion($invoice, $total)) {
            RuleViolation::fail(['xml' => "Operazione esclusa: {$ex}"]);
        }
        if ($invoice->direction === InvoiceDirection::Acquisto && $invoice->xb_relevant !== false && ! $invoice->activeRegistration()?->integration_id) {
            RuleViolation::fail(['xml' => 'Prima registra l’integrazione IVA (Integra IVA / Reverse charge) oppure indica che l’operazione non è rilevante in Italia.']);
        }
        $r = app(XmlExporter::class)->build($company, $invoice);
        if ($r['errors']) {
            return ['export' => null, 'errors' => $r['errors']];
        }

        return DB::transaction(function () use ($company, $invoice, $actor, $r) {
            Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $hash = hash('sha256', $r['xml']);
            $last = XmlExport::where('invoice_id', $invoice->id)->orderByDesc('version')->first();
            if ($last && $last->sha256 === $hash) {
                return ['export' => $last, 'errors' => []];
            }
            $version = ($last?->version ?? 0) + 1;
            $int = InvoiceIntegration::where('invoice_id', $invoice->id)->orderByDesc('id')->first();
            $export = XmlExport::create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'integration_id' => $invoice->direction === InvoiceDirection::Acquisto ? $int?->id : null,
                'doc_type' => $r['doc_type'], 'version' => $version, 'filename' => 'IT'.$company->partita_iva.'_'.$r['doc_type'].'_'.$invoice->id.'_v'.$version.'.xml',
                'content' => $r['xml'], 'sha256' => $hash, 'generated_at' => now(), 'created_by' => $actor->id]);
            Audit::record('xml.generated', $actor, $company, $invoice, data: ['doc_type' => $r['doc_type'], 'version' => $version, 'sha256' => $hash]);

            return ['export' => $export, 'errors' => []];
        });
    }

    public function downloaded(Company $company, XmlExport $export, User $actor): void
    {
        XmlExport::whereKey($export->id)->update(['downloads' => DB::raw('downloads + 1'), 'downloaded_at' => now()]);
        Audit::record('xml.downloaded', $actor, $company, Invoice::find($export->invoice_id), data: ['version' => $export->version, 'filename' => $export->filename]);
    }
}
