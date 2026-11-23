<?php

namespace App\Domain\Issuing;

use App\Domain\Import\FatturaPaSchema;
use App\Domain\Import\SafeXml;
use App\Models\Company;
use DOMDocument;
use DOMElement;
use DOMNode;
use Dompdf\Dompdf;
use Dompdf\Options;
use DOMXPath;
use Illuminate\Support\Facades\Storage;

/**
 * Anteprima e PDF di una fattura elettronica, ricavati dal file FatturaPA (XML): ciò che si vede è ciò che il file contiene, non i dati
 * del modulo. Il PDF è una copia di cortesia; il documento fiscale resta il file XML. Funziona per le fatture emesse con Semplicon
 * e per ogni XML importato (non firmato .p7m).
 */
final class InvoiceXmlView
{
    private DOMXPath $xp;

    /**
     * @return array{format: string, dest_code: ?string, dest_pec: ?string, cedente: array, cessionario: array, bodies: list<array>}
     */
    public function fromXml(string $xml): array
    {
        $dom = SafeXml::parse($xml);
        $this->xp = new DOMXPath($dom);
        $root = $dom->documentElement;
        $header = $this->one('FatturaElettronicaHeader', $root);
        $bodies = [];
        foreach ($this->xp->query('FatturaElettronicaBody', $root) as $b) {
            $bodies[] = $this->body($b);
        }

        return [
            'format' => $root->getAttribute('versione'),
            'dest_code' => $this->text('DatiTrasmissione/CodiceDestinatario', $header), 'dest_pec' => $this->text('DatiTrasmissione/PECDestinatario', $header),
            'cedente' => $this->party('CedentePrestatore', $header, true), 'cessionario' => $this->party('CessionarioCommittente', $header, false),
            'bodies' => $bodies,
        ];
    }

    /** Documento dell'anteprima/PDF come HTML (stesso per schermo e PDF). */
    public function html(array $doc, int $bodyIndex = 0, bool $preview = false, ?string $logo = null): string
    {
        return view('pdf.invoice', ['doc' => $doc, 'b' => $doc['bodies'][$bodyIndex] ?? $doc['bodies'][0], 'preview' => $preview, 'generatedAt' => now(), 'logo' => $logo])->render();
    }

    /** Logo dell'azienda come Data-URI, solo se è essa stessa cedente del documento (non per fatture passive di terzi). */
    public function logoFor(Company $company, array $doc): ?string
    {
        if (! $company->logo_path || ($doc['cedente']['vat'] ?? '') !== 'IT'.$company->partita_iva || ! Storage::disk('local')->exists($company->logo_path)) {
            return null;
        }

        $raw = Storage::disk('local')->get($company->logo_path);
        // Il documento è interamente in grigio: anche il logo viene incorporato in scala di grigi (l'originale resta salvato invariato)
        $img = @imagecreatefromstring($raw);
        if ($img) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefilter($img, IMG_FILTER_GRAYSCALE);
            ob_start();
            imagepng($img);
            $raw = (string) ob_get_clean();
        }

        return 'data:image/png;base64,'.base64_encode($raw);
    }

    public function pdf(string $html): string
    {
        $o = new Options;
        $o->setIsRemoteEnabled(false);
        $o->setDefaultFont('DejaVu Sans');
        $d = new Dompdf($o);
        $d->loadHtml($html, 'UTF-8');
        $d->setPaper('A4', 'portrait');
        $d->render();

        return $d->output();
    }

    private function party(string $node, DOMElement $header, bool $withContacts): array
    {
        $a = $this->one("{$node}/DatiAnagrafici", $header);
        $name = $this->text('Anagrafica/Denominazione', $a) ?: trim($this->text('Anagrafica/Nome', $a).' '.$this->text('Anagrafica/Cognome', $a));
        $sede = $this->xp->query("{$node}/Sede", $header)->item(0);
        $addr = $sede ? trim($this->text('Indirizzo', $sede).' '.$this->text('NumeroCivico', $sede)) : '';
        $city = $sede ? trim($this->text('CAP', $sede).' '.$this->text('Comune', $sede).($this->text('Provincia', $sede) ? ' ('.$this->text('Provincia', $sede).')' : '')) : '';
        $out = ['name' => $name, 'vat' => ($this->text('IdFiscaleIVA/IdPaese', $a) ?? '').($this->text('IdFiscaleIVA/IdCodice', $a) ?? ''), 'cf' => $this->text('CodiceFiscale', $a),
            'address' => $addr, 'city' => $city, 'country' => $sede ? $this->text('Nazione', $sede) : null, 'regime' => null, 'regime_label' => null, 'phone' => null, 'email' => null];
        if ($withContacts) {
            $rf = $this->text('RegimeFiscale', $a);
            $out['regime'] = $rf;
            $out['regime_label'] = $rf ? ($rf.' · '.(InvoiceIssuer::REGIMES[$rf] ?? '')) : null;
            $out['phone'] = $this->text('Contatti/Telefono', $this->one($node, $header));
            $out['email'] = $this->text('Contatti/Email', $this->one($node, $header));
        }

        return $out;
    }

    private function body(DOMElement $b): array
    {
        $g = $this->xp->query('DatiGenerali/DatiGeneraliDocumento', $b)->item(0);
        $tipo = $g ? $this->text('TipoDocumento', $g) : null;
        $lines = [];
        foreach ($this->xp->query('DatiBeniServizi/DettaglioLinee', $b) as $l) {
            $sc = [];
            foreach ($this->xp->query('ScontoMaggiorazione', $l) as $s) {
                $sc[] = ($this->text('Tipo', $s) === 'MG' ? '+' : '−').($this->text('Percentuale', $s) ? $this->text('Percentuale', $s).'%' : $this->text('Importo', $s).' €');
            }
            $lines[] = ['no' => $this->text('NumeroLinea', $l), 'description' => $this->text('Descrizione', $l), 'qty' => $this->text('Quantita', $l), 'unit' => $this->text('UnitaMisura', $l), 'price' => $this->text('PrezzoUnitario', $l),
                'discount' => implode(' ', $sc), 'total' => $this->text('PrezzoTotale', $l), 'rate' => $this->text('AliquotaIVA', $l), 'natura' => $this->text('Natura', $l)];
        }
        $riep = [];
        foreach ($this->xp->query('DatiBeniServizi/DatiRiepilogo', $b) as $r) {
            $n = $this->text('Natura', $r);
            $riep[] = ['rate' => $this->text('AliquotaIVA', $r), 'natura' => $n, 'natura_label' => $n ? (FatturaPaSchema::label('NaturaType', $n) ?? '') : '', 'taxable' => $this->text('ImponibileImporto', $r), 'tax' => $this->text('Imposta', $r),
                'esig' => $this->text('EsigibilitaIVA', $r), 'ref' => $this->text('RiferimentoNormativo', $r)];
        }
        $casse = [];
        if ($g) {
            foreach ($this->xp->query('DatiCassaPrevidenziale', $g) as $c) {
                $t = $this->text('TipoCassa', $c);
                $casse[] = ['type' => $t, 'label' => FatturaPaSchema::label('TipoCassaType', $t) ?? '', 'rate' => $this->text('AlCassa', $c), 'amount' => $this->text('ImportoContributoCassa', $c), 'base' => $this->text('ImponibileCassa', $c),
                    'vat' => $this->text('AliquotaIVA', $c), 'natura' => $this->text('Natura', $c)];
            }
        }
        $rit = [];
        if ($g) {
            foreach ($this->xp->query('DatiRitenuta', $g) as $r) {
                $rit[] = ['type' => $this->text('TipoRitenuta', $r), 'amount' => $this->text('ImportoRitenuta', $r), 'rate' => $this->text('AliquotaRitenuta', $r), 'causale' => $this->text('CausalePagamento', $r)];
            }
        }
        $pay = [];
        foreach ($this->xp->query('DatiPagamento/DettaglioPagamento', $b) as $p) {
            $m = $this->text('ModalitaPagamento', $p);
            $pay[] = ['mode' => $m, 'mode_label' => $m ? (FatturaPaSchema::label('ModalitaPagamentoType', $m) ?? $m) : '', 'due' => $this->text('DataScadenzaPagamento', $p), 'amount' => $this->text('ImportoPagamento', $p), 'iban' => $this->text('IBAN', $p), 'bank' => $this->text('IstitutoFinanziario', $p)];
        }
        $links = [];
        foreach ($this->xp->query('DatiGenerali/DatiFattureCollegate', $b) as $f) {
            $links[] = ['number' => $this->text('IdDocumento', $f), 'date' => $this->text('Data', $f)];
        }
        $causali = [];
        if ($g) {
            foreach ($this->xp->query('Causale', $g) as $c) {
                $causali[] = trim($c->textContent);
            }
        }
        $taxable = $this->sum(array_column($riep, 'taxable'));
        $tax = $this->sum(array_column($riep, 'tax'));
        $total = $g ? $this->text('ImportoTotaleDocumento', $g) : null;
        $bollo = $g ? $this->xp->query('DatiBollo', $g)->item(0) : null;

        return [
            'type' => $tipo, 'type_label' => $tipo ? (InvoiceIssuer::DOC_TYPES[$tipo] ?? FatturaPaSchema::label('TipoDocumentoType', $tipo) ?? $tipo) : '', 'currency' => $g ? ($this->text('Divisa', $g) ?: 'EUR') : 'EUR', 'date' => $g ? $this->text('Data', $g) : null,
            'number' => $g ? $this->text('Numero', $g) : null, 'causali' => $causali, 'bollo' => $bollo ? $this->text('ImportoBollo', $bollo) : null, 'casse' => $casse, 'ritenute' => $rit, 'links' => $links, 'lines' => $lines, 'riepilogo' => $riep,
            'payments' => $pay, 'taxable' => $taxable, 'tax' => $tax, 'total' => $total ?: $this->add($this->add($taxable, $tax), $this->sum(array_column($casse, 'amount'))),
            'withholding' => $this->sum(array_column($rit, 'amount')), 'to_pay' => $pay ? $this->sum(array_column($pay, 'amount')) : null,
        ];
    }

    /** Somma di numeri decimali come stringa in centesimi (nessun float). */
    private function sum(array $vals): string
    {
        $c = 0;
        foreach ($vals as $v) {
            $c += $this->cents((string) $v);
        }

        return sprintf('%s%d.%02d', $c < 0 ? '-' : '', intdiv(abs($c), 100), abs($c) % 100);
    }

    private function add(string $a, string $b): string
    {
        return $this->sum([$a, $b]);
    }

    private function cents(string $v): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($v), $m)) {
            return 0;
        }
        $c = (int) $m[2] * 100 + (int) str_pad(substr($m[3] ?? '', 0, 2), 2, '0');

        return $m[1] === '-' ? -$c : $c;
    }

    private function one(string $path, DOMNode $ctx): DOMElement
    {
        return $this->xp->query($path, $ctx)->item(0) ?? (new DOMDocument)->createElement('x');
    }

    private function text(string $path, ?DOMNode $ctx): ?string
    {
        if (! $ctx) {
            return null;
        }
        $n = $this->xp->query($path, $ctx)->item(0);
        $t = $n ? trim($n->textContent) : '';

        return $t === '' ? null : $t;
    }
}
