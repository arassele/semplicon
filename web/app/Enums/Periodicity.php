<?php

namespace App\Enums;

/** Cadenza di liquidazione. L'abilitazione alla cadenza trimestrale la verifica lo studio, non il software. */
enum Periodicity: string
{
    case Mensile = 'mensile';
    case Trimestrale = 'trimestrale';

    public function label(): string
    {
        return match ($this) {
            self::Mensile => 'Mensile',
            self::Trimestrale => 'Trimestrale',
        };
    }
}
