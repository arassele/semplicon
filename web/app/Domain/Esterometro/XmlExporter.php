<?php

namespace App\Domain\Esterometro;

use App\Domain\Import\FatturaPaSchema;
use App\Domain\Import\ImportError;
use App\Enums\InvoiceDirection;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceIntegration;
use App\Support\Decimal;
use DOMDocument;
use DOMElement;

/**
 * Genera FatturaPA FPR12 per le operazioni transfrontaliere (sezione 6/7) dagli stessi dati della registrazione:
 * TD17/TD18/TD19 dall'integrazione, acquisti esteri non rilevanti con natura N2.2 senza IVA italiana,
 * fatture attive verso soggetti esteri con CodiceDestinatario «XXXXXXX». Verifica rispetto all'XSD ufficiale.
 * Gli errori vengono mappati sui campi di input (field, label, message, link).
 */
final class XmlExporter
{
    private DOMDocument $dom;

    /** @return array{xml: ?string, doc_type: ?string, number: ?string, errors: list<array{field: string, label: string, message: string, link: string}>} */
    public function build(Company $company, Invoice $invoice): array
    {
        $invoice->loadMissing('vatSummaries', 'lines');
        $base = "/aziende/{$company->id}";
        $companyLink = "{$base}/dati-azienda";
        $invLink = $invoice->source === 'manuale' ? "{$base}/fatture/{$invoice->id}/modifica" : "{$base}/fatture/{$invoice->id}";
        $errors = [];
        $err = function (string $field, string $label, string $message, string $link) use (&$errors) {
            $errors[] = compact('field', 'label', 'message', 'link');
        };

        $purchase = $invoice->direction === InvoiceDirection::Acquisto;
        $integration = $purchase ? InvoiceIntegration::where('invoice_id', $invoice->id)->orderByDesc('id')->first() : null;
        if ($integration && ! $invoice->activeRegistration()?->integration_id) {
            $integration = null; // integrazione stornata
        }
        $foreignSide = $purchase ? 'supplier' : 'customer';
        $foreign = ['name' => $invoice->{$foreignSide.'_name'}, 'vat' => $invoice->{$foreignSide.'_vat'}, 'cf' => $invoice->{$foreignSide.'_cf'},
            'country' => IntegrationService::country($invoice, $foreignSide), 'address' => $invoice->{$foreignSide.'_address'} ?? []];

        // Azienda (Dati azienda)
        $own = ['name' => $company->name, 'vat' => $company->partita_iva ? 'IT'.$company->partita_iva : null, 'cf' => $company->codice_fiscale, 'country' => 'IT',
            'address' => ['indirizzo' => $company->indirizzo, 'civico' => $company->numero_civico, 'cap' => $company->cap, 'comune' => $company->comune, 'provincia' => $company->provincia, 'nazione' => $company->nazione ?: 'IT']];
        if (! $company->partita_iva) {
            $err('company.partita_iva', 'Partita IVA dell’azienda', 'Manca la partita IVA dell’azienda.', $companyLink);
        }
        foreach (['indirizzo' => 'Indirizzo', 'cap' => 'CAP', 'comune' => 'Comune'] as $k => $l) {
            if (! ($own['address'][$k] ?? null)) {
                $err("company.{$k}", "{$l} della sede dell’azienda", "Manca: {$l} della sede legale (Dati azienda).", $companyLink);
            }
        }
        if (($own['address']['cap'] ?? null) && ! preg_match('/^\d{5}$/', $own['address']['cap'])) {
            $err('company.cap', 'CAP della sede dell’azienda', 'Il CAP deve avere 5 cifre.', $companyLink);
        }
        if (! $foreign['name']) {
            $err('counterparty_name', 'Denominazione della controparte', 'Manca la denominazione della controparte.', $invLink);
        }
        if (! $foreign['vat'] && ! $foreign['cf']) {
            $err('counterparty_vat', 'Identificativo fiscale della controparte', 'Indica la partita IVA (o l’identificativo fiscale estero) della controparte.', $invLink);
        }
        foreach (['indirizzo' => 'Indirizzo', 'comune' => 'Città'] as $k => $l) {
            if (! ($foreign['address'][$k] ?? null)) {
                $err("counterparty_address.{$k}", "{$l} della controparte", "Manca: {$l} della controparte.", $invLink);
            }
        }

        // Contenuto
        $lines = [];
        $sums = [];
        if ($purchase && $integration) {
            $docType = $integration->doc_type;
            $number = "{$integration->number}/{$integration->year}";
            $date = $integration->doc_date->toDateString();
            $descr = $invoice->lines->pluck('description')->filter()->first() ?: "Integrazione fattura {$invoice->number}";
            foreach ($integration->groups as $g) {
                $lines[] = ['descr' => mb_substr($descr, 0, 1000), 'total' => $g['base'], 'rate' => $g['rate'], 'natura' => null];
                $sums[] = ['rate' => $g['rate'], 'natura' => null, 'taxable' => $g['base'], 'tax' => $g['tax'], 'ref' => null];
            }
        } elseif ($purchase) {
            // operazione estera non rilevante: TD17/18/19 con natura N2.2, nessuna IVA italiana
            $docType = match ($invoice->xb_kind) {
                'beni_ue' => 'TD18', 'beni_17c2', 'beni_extra_ue' => 'TD19', default => 'TD17'
            };
            $number = 'EST-'.$invoice->id;
            $date = ($invoice->receipt_date ?? $invoice->doc_date)->toDateString();
            foreach ($invoice->vatSummaries as $s) {
                $base = Decimal::fromMinor(abs(Decimal::toMinor((string) $s->taxable)));
                $lines[] = ['descr' => mb_substr($invoice->lines->pluck('description')->filter()->first() ?: "Fattura {$invoice->number}", 0, 1000), 'total' => $base, 'rate' => '0.00', 'natura' => 'N2.2'];
                $sums[] = ['rate' => '0.00', 'natura' => 'N2.2', 'taxable' => $base, 'tax' => '0.00', 'ref' => 'Operazione non soggetta, art. 7-ter DPR 633/72'];
            }
        } else {
            $docType = $invoice->doc_type;
            $number = $invoice->number;
            $date = $invoice->doc_date->toDateString();
            $src = $invoice->lines->isNotEmpty() ? $invoice->lines->sortBy('line_no') : $invoice->vatSummaries;
            foreach ($src as $l) {
                $lines[] = ['descr' => mb_substr((string) ($l->description ?? '') ?: "Fattura {$invoice->number}", 0, 1000),
                    'total' => Decimal::fromMinor(abs(Decimal::toMinor(Decimal::normalize(preg_replace('/(\.\d{2})\d*$/', '$1', (string) ($l->total ?? $l->taxable)))))),
                    'rate' => Decimal::normalize((string) $l->rate), 'natura' => $l->natura];
            }
            foreach ($invoice->vatSummaries as $s) {
                $sums[] = ['rate' => Decimal::normalize((string) $s->rate), 'natura' => $s->natura, 'taxable' => Decimal::fromMinor(abs(Decimal::toMinor((string) $s->taxable))),
                    'tax' => Decimal::fromMinor(abs(Decimal::toMinor((string) $s->tax))), 'ref' => $s->normative_reference];
            }
        }
        foreach ($sums as $k => $s) {
            if (Decimal::toMinor($s['tax']) === 0 && ! $s['natura'] && Decimal::toMinor($s['rate']) === 0) {
                $err("lines.{$k}.natura", 'Natura dell’operazione', 'Riga senza IVA: indica la natura (es. N2.2, N3.2).', $invLink);
            }
        }
        if ($errors) {
            return ['xml' => null, 'doc_type' => $docType, 'number' => $number, 'errors' => $errors];
        }

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        $root = $this->dom->createElementNS(FatturaPaSchema::NAMESPACE, 'p:FatturaElettronica');
        $root->setAttribute('versione', 'FPR12');
        $this->dom->appendChild($root);
        $h = $this->el($root, 'FatturaElettronicaHeader');
        $dt = $this->el($h, 'DatiTrasmissione');
        $id = $this->el($dt, 'IdTrasmittente');
        $this->el($id, 'IdPaese', 'IT');
        $this->el($id, 'IdCodice', $company->codice_fiscale ?: $company->partita_iva);
        $this->el($dt, 'ProgressivoInvio', strtoupper(substr(base_convert((string) ($invoice->id * 100 + 1), 10, 36), -10)));
        $this->el($dt, 'FormatoTrasmissione', 'FPR12');
        $this->el($dt, 'CodiceDestinatario', $purchase ? ($company->codice_sdi ?: '0000000') : 'XXXXXXX');

        $profile = $company->activeVatProfile((int) substr($date, 0, 4));
        [$ced, $cess] = $purchase ? [$foreign, $own] : [$own, $foreign];
        $this->party($this->el($h, 'CedentePrestatore'), $ced, $purchase ? 'RF18' : ($profile?->forfettario_iva ? 'RF19' : 'RF01'));
        $this->party($this->el($h, 'CessionarioCommittente'), $cess, null);

        $b = $this->el($root, 'FatturaElettronicaBody');
        $dg = $this->el($b, 'DatiGenerali');
        $dgd = $this->el($dg, 'DatiGeneraliDocumento');
        $this->el($dgd, 'TipoDocumento', $docType);
        $this->el($dgd, 'Divisa', 'EUR');
        $this->el($dgd, 'Data', $date);
        $this->el($dgd, 'Numero', mb_substr($number, 0, 20));
        $total = array_sum(array_map(fn ($s) => Decimal::toMinor($s['taxable']) + Decimal::toMinor($s['tax']), $sums));
        $this->el($dgd, 'ImportoTotaleDocumento', Decimal::fromMinor($total));
        if ($purchase) {
            $this->el($dgd, 'Causale', mb_substr(($integration ? 'Integrazione' : 'Comunicazione operazione non rilevante').' fattura n. '.$invoice->number.' del '.$invoice->doc_date->format('d/m/Y')
                .($invoice->currency !== 'EUR' && $invoice->exchange_rate ? " ({$invoice->currency}, cambio ".rtrim(rtrim((string) $invoice->exchange_rate, '0'), '.').')' : ''), 0, 200));
            $fc = $this->el($dg, 'DatiFattureCollegate');
            $this->el($fc, 'IdDocumento', mb_substr($invoice->number, 0, 20));
            $this->el($fc, 'Data', $invoice->doc_date->toDateString());
        }
        $bs = $this->el($b, 'DatiBeniServizi');
        foreach ($lines as $i => $l) {
            $dl = $this->el($bs, 'DettaglioLinee');
            $this->el($dl, 'NumeroLinea', (string) ($i + 1));
            $this->el($dl, 'Descrizione', $l['descr']);
            $this->el($dl, 'PrezzoUnitario', $l['total']);
            $this->el($dl, 'PrezzoTotale', $l['total']);
            $this->el($dl, 'AliquotaIVA', $l['rate']);
            if ($l['natura']) {
                $this->el($dl, 'Natura', $l['natura']);
            }
        }
        foreach ($sums as $s) {
            $r = $this->el($bs, 'DatiRiepilogo');
            $this->el($r, 'AliquotaIVA', $s['rate']);
            if ($s['natura']) {
                $this->el($r, 'Natura', $s['natura']);
            }
            $this->el($r, 'ImponibileImporto', $s['taxable']);
            $this->el($r, 'Imposta', $s['tax']);
            if ($s['natura'] && $s['ref']) {
                $this->el($r, 'RiferimentoNormativo', mb_substr($s['ref'], 0, 100));
            } elseif (! $s['natura']) {
                $this->el($r, 'EsigibilitaIVA', 'I');
            }
        }

        $xml = $this->dom->saveXML();
        $check = new DOMDocument;
        $check->loadXML($xml);
        try {
            FatturaPaSchema::validate($check);
        } catch (ImportError $e) {
            foreach ($e->details ?: [$e->getMessage()] as $m) {
                $errors[] = self::mapSchemaError((string) $m, $companyLink, $invLink);
            }

            return ['xml' => null, 'doc_type' => $docType, 'number' => $number, 'errors' => $errors];
        }

        return ['xml' => $xml, 'doc_type' => $docType, 'number' => $number, 'errors' => []];
    }

    /** Messaggio XSD → campo dell'interfaccia. */
    public static function mapSchemaError(string $m, string $companyLink, string $invLink): array
    {
        $map = ['CAP' => ['address.cap', 'CAP'], 'Provincia' => ['address.provincia', 'Provincia (2 lettere)'], 'Nazione' => ['address.nazione', 'Nazione (codice ISO a 2 lettere)'],
            'IdCodice' => ['vat', 'Partita IVA / identificativo fiscale'], 'IdPaese' => ['country', 'Paese'], 'Denominazione' => ['name', 'Denominazione'],
            'Indirizzo' => ['address.indirizzo', 'Indirizzo'], 'Comune' => ['address.comune', 'Comune / città'], 'Descrizione' => ['lines.description', 'Descrizione della riga'],
            'Natura' => ['lines.natura', 'Natura'], 'Numero' => ['number', 'Numero documento']];
        foreach ($map as $el => [$field, $label]) {
            if (str_contains($m, "'{$el}'")) {
                return ['field' => $field, 'label' => $label, 'message' => $m, 'link' => in_array($el, ['Descrizione', 'Natura', 'Numero'], true) ? $invLink : $invLink];
            }
        }

        return ['field' => 'xml', 'label' => 'XML', 'message' => $m, 'link' => $invLink];
    }

    private function party(DOMElement $node, array $p, ?string $regime): void
    {
        $da = $this->el($node, 'DatiAnagrafici');
        $vat = $p['vat'];
        if ($vat || $p['country'] !== 'IT') {
            $idf = $this->el($da, 'IdFiscaleIVA');
            $this->el($idf, 'IdPaese', $vat ? strtoupper(substr($vat, 0, 2)) : $p['country']);
            $code = $vat ? preg_replace('/\s+/', '', substr($vat, 2)) : ($p['cf'] ?: '99999999999');
            $this->el($idf, 'IdCodice', mb_substr($code, 0, 28));
        }
        if ($p['country'] === 'IT' && $p['cf']) {
            $this->el($da, 'CodiceFiscale', strtoupper($p['cf']));
        }
        $an = $this->el($da, 'Anagrafica');
        $this->el($an, 'Denominazione', mb_substr((string) $p['name'], 0, 80));
        if ($regime) {
            $this->el($da, 'RegimeFiscale', $regime);
        }
        $a = $p['address'];
        $sede = $this->el($node, 'Sede');
        $this->el($sede, 'Indirizzo', mb_substr((string) ($a['indirizzo'] ?? ''), 0, 60));
        if (! empty($a['civico'])) {
            $this->el($sede, 'NumeroCivico', mb_substr($a['civico'], 0, 8));
        }
        $this->el($sede, 'CAP', $p['country'] === 'IT' ? (string) ($a['cap'] ?? '') : '00000');
        $this->el($sede, 'Comune', mb_substr((string) ($a['comune'] ?? ''), 0, 60));
        if ($p['country'] === 'IT' && ! empty($a['provincia'])) {
            $this->el($sede, 'Provincia', strtoupper($a['provincia']));
        }
        $this->el($sede, 'Nazione', strtoupper($a['nazione'] ?? $p['country']));
    }

    private function el(DOMElement $parent, string $name, ?string $text = null): DOMElement
    {
        $e = $this->dom->createElement($name);
        if ($text !== null) {
            $e->appendChild($this->dom->createTextNode($text));
        }
        $parent->appendChild($e);

        return $e;
    }
}
