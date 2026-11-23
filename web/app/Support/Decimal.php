<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Importi in denaro come testo con numero fisso di decimali. Calcolo con interi (centesimi), mai float.
 * FatturaPA consente fino a 11 cifre intere e 2 decimali per gli importi; ci sta in un intero a 64 bit.
 */
final class Decimal
{
    public static function isValid(string $value, int $maxScale = 8): bool
    {
        return (bool) preg_match('/^-?\d{1,13}(\.\d{1,'.$maxScale.'})?$/', trim($value));
    }

    /** "12.3" → 1230 con scala 2. Più decimali della scala sono un errore, non vengono arrotondati in silenzio. */
    public static function toMinor(string $value, int $scale = 2): int
    {
        $v = trim($value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $v, $m)) {
            throw new InvalidArgumentException("Importo non valido: {$value}");
        }
        $frac = $m[3] ?? '';
        if (strlen(rtrim($frac, '0')) > $scale) {
            throw new InvalidArgumentException("Troppi decimali: {$value}");
        }
        $minor = (int) $m[2] * (10 ** $scale) + (int) str_pad(substr($frac, 0, $scale), $scale, '0');

        return $m[1] === '-' ? -$minor : $minor;
    }

    public static function fromMinor(int $minor, int $scale = 2): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = (string) abs($minor);
        $abs = str_pad($abs, $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$scale).'.'.substr($abs, -$scale);
    }

    /** Portare a scala fissa, es. "5" → "5.00". */
    public static function normalize(string $value, int $scale = 2): string
    {
        return self::fromMinor(self::toMinor($value, $scale), $scale);
    }
}
