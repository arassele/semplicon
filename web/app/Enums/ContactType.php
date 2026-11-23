<?php

namespace App\Enums;

enum ContactType: string
{
    case Email = 'email';
    case Pec = 'pec';
    case Telefono = 'telefono';
    case Cellulare = 'cellulare';
    case Fax = 'fax';
    case Sito = 'sito';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Pec => 'PEC residenza/sede',
            self::Telefono => 'Telefono',
            self::Cellulare => 'Cellulare',
            self::Fax => 'Fax',
            self::Sito => 'Sito web',
        };
    }

    public function isEmail(): bool
    {
        return $this === self::Email || $this === self::Pec;
    }
}
