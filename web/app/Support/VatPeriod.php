<?php

namespace App\Support;

/** Periodi IVA: mese (1–12) per mensile, trimestre (1–4) per trimestrale. */
final class VatPeriod
{
    private const MONTHS = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

    public static function ofDate(string $isoDate, string $periodicity): array
    {
        $year = (int) substr($isoDate, 0, 4);
        $month = (int) substr($isoDate, 5, 2);

        return ['year' => $year, 'period' => $periodicity === 'trimestrale' ? intdiv($month - 1, 3) + 1 : $month];
    }

    public static function label(int $year, int $period, string $periodicity): string
    {
        return $periodicity === 'trimestrale' ? "{$period}° trimestre {$year}" : self::MONTHS[$period]." {$year}";
    }

    public static function max(string $periodicity): int
    {
        return $periodicity === 'trimestrale' ? 4 : 12;
    }

    /** Primo giorno del periodo, per confronti con le date dei documenti. */
    public static function start(int $year, int $period, string $periodicity): string
    {
        $month = $periodicity === 'trimestrale' ? ($period - 1) * 3 + 1 : $period;

        return sprintf('%04d-%02d-01', $year, $month);
    }

    public static function end(int $year, int $period, string $periodicity): string
    {
        $month = $periodicity === 'trimestrale' ? $period * 3 : $period;

        return date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    }
}
