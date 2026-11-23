<?php

namespace App\Domain\Issuing;

use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiGenerali\DatiCassaPrevidenziale;

/**
 * Fino a due blocchi DatiCassaPrevidenziale (la libreria ne conosce solo uno). L'ordine degli elementi segue lo schema ufficiale.
 */
final class CassaBlock extends DatiCassaPrevidenziale
{
    /** @param list<array{type: string, rate: string, contribution: int, base: int, vat_rate: string, natura: ?string, ritenuta: bool}> $items */
    public function __construct(private array $items)
    {
        parent::__construct('', 0, 0, 0, 0, null, null, null);
    }

    public function toXmlBlock(\XMLWriter $writer)
    {
        foreach ($this->items as $c) {
            $writer->startElement('DatiCassaPrevidenziale');
            $writer->writeElement('TipoCassa', $c['type']);
            $writer->writeElement('AlCassa', fe_number_format((float) $c['rate'], 2));
            $writer->writeElement('ImportoContributoCassa', fe_number_format($c['contribution'] / 100, 2));
            $writer->writeElement('ImponibileCassa', fe_number_format($c['base'] / 100, 2));
            $writer->writeElement('AliquotaIVA', fe_number_format((float) $c['vat_rate'], 2));
            if ($c['ritenuta']) {
                $writer->writeElement('Ritenuta', 'SI');
            }
            if ($c['natura']) {
                $writer->writeElement('Natura', $c['natura']);
            }
            $writer->endElement();
        }

        return $writer;
    }
}
