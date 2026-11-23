<?php

namespace App\Domain\Import;

use App\Support\Decimal;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Legge una FatturaPA valida rispetto allo schema. Gli elementi figli sono nello schema senza namespace (unqualified),
 * solo l'elemento radice è qualificato. Più FatturaElettronicaBody per file vengono conservati.
 */
final class FatturaParser
{
    private DOMXPath $xp;

    public function parse(DOMDocument $dom): array
    {
        $this->xp = new DOMXPath($dom);
        $root = $dom->documentElement;
        $header = $this->one('FatturaElettronicaHeader', $root);

        $bodies = [];
        foreach ($this->xp->query('FatturaElettronicaBody', $root) as $i => $body) {
            $bodies[] = $this->body($body, $i + 1);
        }

        return [
            'format' => $root->getAttribute('versione'),
            'supplier' => [...$this->party($this->one('CedentePrestatore/DatiAnagrafici', $header)), 'address' => $this->address($this->xp->query('CedentePrestatore/Sede', $header)->item(0))],
            'customer' => [...$this->party($this->one('CessionarioCommittente/DatiAnagrafici', $header)), 'address' => $this->address($this->xp->query('CessionarioCommittente/Sede', $header)->item(0))],
            'bodies' => $bodies,
        ];
    }

    private function party(DOMElement $anagrafici): array
    {
        $country = $this->text('IdFiscaleIVA/IdPaese', $anagrafici);
        $code = $this->text('IdFiscaleIVA/IdCodice', $anagrafici);
        $name = $this->text('Anagrafica/Denominazione', $anagrafici)
            ?? trim(($this->text('Anagrafica/Nome', $anagrafici) ?? '').' '.($this->text('Anagrafica/Cognome', $anagrafici) ?? ''));

        return [
            'name' => mb_substr($name, 0, 200),
            'country' => $country,
            'vat_code' => $code,
            'vat' => $country && $code ? strtoupper($country.$code) : null,
            'cf' => ($cf = $this->text('CodiceFiscale', $anagrafici)) ? strtoupper($cf) : null,
        ];
    }

    /** Sede (Indirizzo, NumeroCivico, CAP, Comune, Provincia, Nazione) per l'esportazione XML e la lista di controllo. */
    private function address(?DOMElement $sede): ?array
    {
        if (! $sede) {
            return null;
        }

        return array_filter(['indirizzo' => $this->text('Indirizzo', $sede), 'civico' => $this->text('NumeroCivico', $sede), 'cap' => $this->text('CAP', $sede),
            'comune' => $this->text('Comune', $sede), 'provincia' => $this->text('Provincia', $sede), 'nazione' => $this->text('Nazione', $sede)], fn ($v) => $v !== null);
    }

    private function body(DOMElement $body, int $index): array
    {
        $doc = $this->one('DatiGenerali/DatiGeneraliDocumento', $body);

        $summaries = [];
        foreach ($this->xp->query('DatiBeniServizi/DatiRiepilogo', $body) as $r) {
            $summaries[] = [
                'rate' => $this->amount('AliquotaIVA', $r),
                'natura' => $this->text('Natura', $r),
                'taxable' => $this->amount('ImponibileImporto', $r),
                'tax' => $this->amount('Imposta', $r),
                'esigibilita' => $this->text('EsigibilitaIVA', $r),
                'normative_reference' => ($ref = $this->text('RiferimentoNormativo', $r)) ? mb_substr($ref, 0, 100) : null,
            ];
        }

        $lines = [];
        foreach ($this->xp->query('DatiBeniServizi/DettaglioLinee', $body) as $l) {
            $lines[] = [
                'line_no' => (int) $this->text('NumeroLinea', $l),
                'description' => (string) $this->text('Descrizione', $l),
                'quantity' => $this->decimalOrNull('Quantita', $l),
                'unit' => $this->text('UnitaMisura', $l),
                'unit_price' => $this->decimal('PrezzoUnitario', $l),
                'total' => $this->decimal('PrezzoTotale', $l),
                'rate' => $this->amount('AliquotaIVA', $l),
                'natura' => $this->text('Natura', $l),
            ];
        }

        // Scadenziario: dettagli di pagamento per corpo fattura (DatiPagamento/DettaglioPagamento) e ritenuta.
        $payments = [];
        foreach ($this->xp->query('DatiPagamento', $body) as $dp) {
            $cond = $this->text('CondizioniPagamento', $dp);
            foreach ($this->xp->query('DettaglioPagamento', $dp) as $d) {
                $payments[] = [
                    'condition' => $cond, 'mode' => $this->text('ModalitaPagamento', $d),
                    'due_date' => $this->text('DataScadenzaPagamento', $d), 'amount' => $this->amount('ImportoPagamento', $d),
                ];
            }
        }
        $ritenuta = 0;
        foreach ($this->xp->query('DatiRitenuta', $doc) as $r) {
            $ritenuta += Decimal::toMinor($this->amount('ImportoRitenuta', $r));
        }

        $taxable = array_sum(array_map(fn ($s) => Decimal::toMinor($s['taxable']), $summaries));
        $tax = array_sum(array_map(fn ($s) => Decimal::toMinor($s['tax']), $summaries));
        $total = $this->text('ImportoTotaleDocumento', $doc);

        return [
            'index' => $index,
            'doc_type' => $this->text('TipoDocumento', $doc),
            'currency' => $this->text('Divisa', $doc),
            'date' => $this->text('Data', $doc),
            'number' => $this->text('Numero', $doc),
            'total_amount' => $total !== null ? Decimal::normalize($total) : null,
            'rounding' => ($r = $this->text('Arrotondamento', $doc)) !== null ? Decimal::normalize($r) : null,
            'taxable_total' => Decimal::fromMinor($taxable),
            'tax_total' => Decimal::fromMinor($tax),
            'summaries' => $summaries,
            'lines' => $lines,
            'payments' => $payments,
            'ritenuta' => Decimal::fromMinor($ritenuta),
            // Forma canonica del body: stesso contenuto produce lo stesso hash, indipendentemente dal rientro nell'header.
            'content_hash' => hash('sha256', $body->C14N(false, false)),
        ];
    }

    private function one(string $path, DOMElement $ctx): DOMElement
    {
        $node = $this->xp->query($path, $ctx)->item(0);
        if (! $node instanceof DOMElement) {
            throw new ImportError("Elemento mancante: {$path}");
        }

        return $node;
    }

    private function text(string $path, DOMElement $ctx): ?string
    {
        $node = $this->xp->query($path, $ctx)->item(0);
        $value = $node ? trim($node->textContent) : '';

        return $value === '' ? null : $value;
    }

    private function amount(string $path, DOMElement $ctx): string
    {
        $value = $this->text($path, $ctx) ?? throw new ImportError("Importo mancante: {$path}");
        try {
            return Decimal::normalize($value, 2);
        } catch (InvalidArgumentException) {
            throw new ImportError("Importo non valido in {$path}: {$value}");
        }
    }

    private function decimal(string $path, DOMElement $ctx): string
    {
        $value = $this->text($path, $ctx) ?? throw new ImportError("Valore mancante: {$path}");
        if (! Decimal::isValid($value, 8)) {
            throw new ImportError("Valore non valido in {$path}: {$value}");
        }

        return $value;
    }

    private function decimalOrNull(string $path, DOMElement $ctx): ?string
    {
        return $this->text($path, $ctx) === null ? null : $this->decimal($path, $ctx);
    }
}
