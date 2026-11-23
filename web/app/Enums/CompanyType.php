<?php

namespace App\Enums;

/** Tipologia del soggetto (Dati azienda). */
enum CompanyType: string
{
    case Professionista = 'professionista';
    case DittaIndividuale = 'ditta_individuale';
    case Societa = 'societa';

    public function label(): string
    {
        return match ($this) {
            self::Professionista => 'Professionista',
            self::DittaIndividuale => 'Ditta individuale',
            self::Societa => 'Società',
        };
    }

    /** Persona fisica: cognome e nome al posto della denominazione. */
    public function isPerson(): bool
    {
        return $this !== self::Societa;
    }
}
