<?php

namespace App\Domain\Issuing;

use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaHeader\Common\DatiAnagrafici;

/**
 * Anagrafica di una persona fisica: Nome e Cognome nello schema stanno dentro Anagrafica. La libreria li scrive come campi liberi
 * dopo RegimeFiscale e viola così lo schema (riguarda i liberi professionisti come emittenti e i privati come clienti).
 */
final class PersonAnagrafica extends DatiAnagrafici
{
    public function __construct(string $codiceFiscale, private string $nome, private string $cognome, string $idPaese = '', string $idCodice = '', ?string $regimeFiscale = null)
    {
        parent::__construct($codiceFiscale, '', $idPaese, $idCodice, $regimeFiscale);
    }

    public function toXmlBlock(\XMLWriter $writer)
    {
        $writer->startElement('DatiAnagrafici');
        if ($this->idCodice && $this->idPaese) {
            $writer->startElement('IdFiscaleIVA');
            $writer->writeElement('IdPaese', $this->idPaese);
            $writer->writeElement('IdCodice', $this->idCodice);
            $writer->endElement();
        }
        if ($this->codiceFiscale) {
            $writer->writeElement('CodiceFiscale', $this->codiceFiscale);
        }
        $writer->startElement('Anagrafica');
        $writer->writeElement('Nome', $this->nome);
        $writer->writeElement('Cognome', $this->cognome);
        $writer->endElement();
        if ($this->regimeFiscale) {
            $writer->writeElement('RegimeFiscale', $this->regimeFiscale);
        }
        $writer->endElement();

        return $writer;
    }
}
