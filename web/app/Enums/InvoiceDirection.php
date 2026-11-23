<?php

namespace App\Enums;

enum InvoiceDirection: string
{
    case Acquisto = 'acquisto';
    case Vendita = 'vendita';

    public function label(): string
    {
        return match ($this) {
            self::Acquisto => 'Acquisto',
            self::Vendita => 'Vendita',
        };
    }
}
