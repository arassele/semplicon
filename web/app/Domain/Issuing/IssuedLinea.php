<?php

namespace App\Domain\Issuing;

use Deved\FatturaElettronica\FatturaElettronica\FatturaElettronicaBody\DatiBeniServizi\Linea;

/**
 * Riga di fattura con totale di riga calcolato da Semplicon (intero in centesimi, con sconto). La libreria calcola la somma
 * con numeri in virgola mobile e non applica gli sconti percentuali come percentuale; per il file vale quindi il valore di Semplicon.
 */
final class IssuedLinea extends Linea
{
    private string $net = '0.00';

    public function withNet(string $net): self
    {
        $this->net = $net;

        return $this;
    }

    public function prezzoTotale($format = true)
    {
        return $this->net;
    }
}
