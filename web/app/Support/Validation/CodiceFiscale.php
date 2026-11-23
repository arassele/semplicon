<?php

namespace App\Support\Validation;

/**
 * Carattere di controllo del codice fiscale (D.M. 23/12/1976).
 * 16 caratteri (persone): caratteri 1–15 secondo le tabelle per posizioni dispari/pari, somma mod 26 → lettera.
 * L'omocodia (cifre sostituite da L–V) viene verificata, perché il carattere è calcolato sui caratteri effettivi.
 * 11 cifre (aziende, associazioni): stessa cifra di controllo della partita IVA.
 */
final class CodiceFiscale
{
    private const ODD = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    public static function isValid(string $value): bool
    {
        $cf = strtoupper(trim($value));
        if (preg_match('/^\d{11}$/', $cf)) {
            return PartitaIva::isValid($cf);
        }
        // Struttura: 6 lettere, 2 cifre (o lettere di omocodia), lettera del mese, giorno, comune, carattere di controllo.
        if (! preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $cf)) {
            return false;
        }

        return self::checkChar(substr($cf, 0, 15)) === $cf[15];
    }

    public static function checkChar(string $first15): string
    {
        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $ch = $first15[$i];
            // Posizione 1, 3, 5 … (base 1) = indice 0, 2, 4 … → tabella «dispari»
            $sum += $i % 2 === 0 ? self::ODD[$ch] : (ctype_digit($ch) ? (int) $ch : ord($ch) - 65);
        }

        return chr(65 + $sum % 26);
    }

    /** Integra 15 caratteri con il carattere di controllo (per test e dati demo). */
    public static function complete(string $first15): string
    {
        $first15 = strtoupper($first15);

        return $first15.self::checkChar($first15);
    }
}
