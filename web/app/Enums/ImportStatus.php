<?php

namespace App\Enums;

enum ImportStatus: string
{
    case InCoda = 'in_coda';
    case InCorso = 'in_corso';
    case Completato = 'completato';

    public function label(): string
    {
        return match ($this) {
            self::InCoda => 'In coda',
            self::InCorso => 'In elaborazione',
            self::Completato => 'Completato',
        };
    }
}
