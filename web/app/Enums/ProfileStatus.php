<?php

namespace App\Enums;

enum ProfileStatus: string
{
    case Bozza = 'bozza';
    case Confermato = 'confermato';
    case Sostituito = 'sostituito';

    public function label(): string
    {
        return match ($this) {
            self::Bozza => 'Bozza',
            self::Confermato => 'Confermato',
            self::Sostituito => 'Sostituito',
        };
    }
}
