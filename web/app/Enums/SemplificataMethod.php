<?php

namespace App\Enums;

/** Variante della contabilità semplificata (R-REG-02). */
enum SemplificataMethod: string
{
    case Competenza = 'competenza';
    case Cassa = 'cassa';

    public function label(): string
    {
        return match ($this) {
            self::Competenza => 'Regime opzionale senza annotazione incassi/pagamenti (per competenza)',
            self::Cassa => 'Regime naturale con annotazione incassi/pagamenti (per cassa)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Competenza => 'per competenza',
            self::Cassa => 'per cassa',
        };
    }
}
