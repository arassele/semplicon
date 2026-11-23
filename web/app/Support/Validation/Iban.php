<?php

namespace App\Support\Validation;

/** Verifica IBAN secondo ISO 13616 (modulo 97). Gli IBAN italiani hanno 27 caratteri. */
final class Iban
{
    public static function normalize(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value));
    }

    public static function isValid(string $value): bool
    {
        $iban = self::normalize($value);
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        if (str_starts_with($iban, 'IT') && strlen($iban) !== 27) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }

        // Modulo 97 a blocchi, senza numeri grandi.
        $mod = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $mod = (int) ($mod.$chunk) % 97;
        }

        return $mod === 1;
    }

    /** ABI e CAB da un IBAN italiano (posizioni 6–10 e 11–15). */
    public static function abiCab(string $value): ?array
    {
        $iban = self::normalize($value);

        return str_starts_with($iban, 'IT') && strlen($iban) === 27
            ? ['abi' => substr($iban, 5, 5), 'cab' => substr($iban, 10, 5)]
            : null;
    }
}
