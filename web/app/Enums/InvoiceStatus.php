<?php

namespace App\Enums;

/** Stato di una fattura: dopo l'importazione, dopo la registrazione o scartata. */
enum InvoiceStatus: string
{
    case Importata = 'importata';
    case DaVerificare = 'da_verificare';
    case Registrata = 'registrata';
    case Scartata = 'scartata';
    case Archiviata = 'archiviata';

    public function label(): string
    {
        return match ($this) {
            self::Importata => 'Da registrare',
            self::DaVerificare => 'Da verificare',
            self::Registrata => 'Registrata',
            self::Scartata => 'Scartata',
            self::Archiviata => 'Archiviata',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Importata || $this === self::DaVerificare;
    }
}
