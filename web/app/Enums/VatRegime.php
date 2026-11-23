<?php

namespace App\Enums;

/**
 * Regime principale (indicazione del committente, 18.11.2026). Vale sempre per un intero anno solare.
 * Vedi docs/FISCAL_RULES.md, R-REG-01.
 */
enum VatRegime: string
{
    case Semplificata = 'semplificata';
    case Professionisti = 'professionisti';

    public function label(): string
    {
        return match ($this) {
            self::Semplificata => 'Contabilità semplificata',
            self::Professionisti => 'Contabilità professionisti',
        };
    }

    /** Solo la contabilità semplificata richiede la scelta della variante. */
    public function needsMethod(): bool
    {
        return $this === self::Semplificata;
    }
}
