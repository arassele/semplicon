<?php

namespace Tests\Support;

/** Genera file FatturaPA sintetici e validi rispetto allo schema (FPR12) per i test. */
final class FatturaBuilder
{
    private array $supplier = ['vat' => ['IT', '11111111111'], 'cf' => null, 'name' => 'Fornitore Test S.r.l.'];

    private array $customer = ['vat' => ['IT', '22222222222'], 'cf' => null, 'name' => 'Cliente Test S.r.l.'];

    private array $bodies = [];

    private string $progressivo = '00001';

    public static function make(): self
    {
        return new self;
    }

    public function supplier(?string $vat, ?string $cf = null, string $name = 'Fornitore Test S.r.l.'): self
    {
        $this->supplier = ['vat' => $vat ? [substr($vat, 0, 2), substr($vat, 2)] : null, 'cf' => $cf, 'name' => $name];

        return $this;
    }

    public function customer(?string $vat, ?string $cf = null, string $name = 'Cliente Test S.r.l.'): self
    {
        $this->customer = ['vat' => $vat ? [substr($vat, 0, 2), substr($vat, 2)] : null, 'cf' => $cf, 'name' => $name];

        return $this;
    }

    public function progressivo(string $p): self
    {
        $this->progressivo = $p;

        return $this;
    }

    /** @param list<array{rate: string, taxable: string, tax: string, natura?: string}> $summaries */
    public function body(string $number = '1', string $date = '2026-03-15', string $type = 'TD01', string $currency = 'EUR', ?string $total = null, array $summaries = [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']], string $description = 'Servizio di prova'): self
    {
        $this->bodies[] = compact('number', 'date', 'type', 'currency', 'total', 'summaries', 'description');

        return $this;
    }

    /** Scadenziario: dettagli di pagamento per l'ultimo corpo creato: [['amount' => '610.00', 'due' => '2026-12-31', 'mode' => 'MP05'], …]. */
    public function payments(array $rates, string $condition = 'TP01'): self
    {
        $this->bodies[count($this->bodies) - 1]['payments'] = $rates;
        $this->bodies[count($this->bodies) - 1]['condition'] = $condition;

        return $this;
    }

    public function ritenuta(string $amount): self
    {
        $this->bodies[count($this->bodies) - 1]['ritenuta'] = $amount;

        return $this;
    }

    public function xml(): string
    {
        $bodies = $this->bodies ?: [['number' => '1', 'date' => '2026-03-15', 'type' => 'TD01', 'currency' => 'EUR', 'total' => null, 'summaries' => [['rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00']], 'description' => 'Servizio di prova']];
        $b = '';
        foreach ($bodies as $body) {
            $lines = '';
            $riep = '';
            foreach ($body['summaries'] as $i => $s) {
                $natura = isset($s['natura']) ? "<Natura>{$s['natura']}</Natura>" : '';
                $lines .= '<DettaglioLinee><NumeroLinea>'.($i + 1).'</NumeroLinea><Descrizione>'.htmlspecialchars($body['description'], ENT_XML1)."</Descrizione><PrezzoUnitario>{$s['taxable']}</PrezzoUnitario><PrezzoTotale>{$s['taxable']}</PrezzoTotale><AliquotaIVA>{$s['rate']}</AliquotaIVA>{$natura}</DettaglioLinee>";
                $esig = isset($s['natura']) ? '' : '<EsigibilitaIVA>'.($s['esigibilita'] ?? 'I').'</EsigibilitaIVA>';
                $ref = isset($s['natura']) ? '<RiferimentoNormativo>Riferimento di prova</RiferimentoNormativo>' : '';
                $riep .= "<DatiRiepilogo><AliquotaIVA>{$s['rate']}</AliquotaIVA>{$natura}<ImponibileImporto>{$s['taxable']}</ImponibileImporto><Imposta>{$s['tax']}</Imposta>{$esig}{$ref}</DatiRiepilogo>";
            }
            $total = $body['total'] !== null ? "<ImportoTotaleDocumento>{$body['total']}</ImportoTotaleDocumento>" : '';
            $rit = isset($body['ritenuta']) ? "<DatiRitenuta><TipoRitenuta>RT01</TipoRitenuta><ImportoRitenuta>{$body['ritenuta']}</ImportoRitenuta><AliquotaRitenuta>20.00</AliquotaRitenuta><CausalePagamento>A</CausalePagamento></DatiRitenuta>" : '';
            $pay = '';
            if (! empty($body['payments'])) {
                $pay = '<DatiPagamento><CondizioniPagamento>'.($body['condition'] ?? 'TP01').'</CondizioniPagamento>';
                foreach ($body['payments'] as $p) {
                    $pay .= '<DettaglioPagamento><ModalitaPagamento>'.($p['mode'] ?? 'MP05').'</ModalitaPagamento>'.(isset($p['due']) ? "<DataScadenzaPagamento>{$p['due']}</DataScadenzaPagamento>" : '')."<ImportoPagamento>{$p['amount']}</ImportoPagamento></DettaglioPagamento>";
                }
                $pay .= '</DatiPagamento>';
            }
            $b .= "<FatturaElettronicaBody><DatiGenerali><DatiGeneraliDocumento><TipoDocumento>{$body['type']}</TipoDocumento><Divisa>{$body['currency']}</Divisa><Data>{$body['date']}</Data><Numero>".htmlspecialchars($body['number'], ENT_XML1)."</Numero>{$rit}{$total}</DatiGeneraliDocumento></DatiGenerali><DatiBeniServizi>{$lines}{$riep}</DatiBeniServizi>{$pay}</FatturaElettronicaBody>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">'
            .'<FatturaElettronicaHeader><DatiTrasmissione><IdTrasmittente><IdPaese>IT</IdPaese><IdCodice>11111111111</IdCodice></IdTrasmittente>'
            ."<ProgressivoInvio>{$this->progressivo}</ProgressivoInvio><FormatoTrasmissione>FPR12</FormatoTrasmissione><CodiceDestinatario>0000000</CodiceDestinatario></DatiTrasmissione>"
            .'<CedentePrestatore><DatiAnagrafici>'.$this->ids($this->supplier).'<Anagrafica><Denominazione>'.htmlspecialchars($this->supplier['name'], ENT_XML1).'</Denominazione></Anagrafica><RegimeFiscale>RF01</RegimeFiscale></DatiAnagrafici>'.$this->sede().'</CedentePrestatore>'
            .'<CessionarioCommittente><DatiAnagrafici>'.$this->ids($this->customer).'<Anagrafica><Denominazione>'.htmlspecialchars($this->customer['name'], ENT_XML1).'</Denominazione></Anagrafica></DatiAnagrafici>'.$this->sede().'</CessionarioCommittente>'
            .'</FatturaElettronicaHeader>'.$b.'</p:FatturaElettronica>';
    }

    private function ids(array $p): string
    {
        $out = $p['vat'] ? "<IdFiscaleIVA><IdPaese>{$p['vat'][0]}</IdPaese><IdCodice>{$p['vat'][1]}</IdCodice></IdFiscaleIVA>" : '';

        return $out.($p['cf'] ? "<CodiceFiscale>{$p['cf']}</CodiceFiscale>" : '');
    }

    private function sede(): string
    {
        return '<Sede><Indirizzo>Via di prova 1</Indirizzo><CAP>39100</CAP><Comune>Bolzano</Comune><Provincia>BZ</Provincia><Nazione>IT</Nazione></Sede>';
    }
}
