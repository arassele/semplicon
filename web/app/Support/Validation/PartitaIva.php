<?php

namespace App\Support\Validation;

/** Cifra di controllo della partita IVA italiana (11 cifre, variante di Luhn). */
final class PartitaIva
{
    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^\d{11}$/', $value) && self::checkDigit(substr($value, 0, 10)) === (int) $value[10];
    }

    /** Cifra di controllo per le prime 10 cifre. */
    public static function checkDigit(string $first10): int
    {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $d = (int) $first10[$i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return (10 - $sum % 10) % 10;
    }

    /** Integra 10 cifre in una partita IVA valida (per test e dati demo). */
    public static function complete(string $first10): string
    {
        return $first10.self::checkDigit($first10);
    }
}
