<?php

namespace App\Domain\Liquidazioni;

use App\Support\Decimal;

/** Tabella delle regole e calendario per anno (config/semplicon_iva.php). */
final class Rules
{
    public static function for(int $year): ?array
    {
        return config("semplicon_iva.years.{$year}");
    }

    public static function cents(array $rules, string $key): int
    {
        return Decimal::toMinor($rules[$key]);
    }

    /** Giorno di scadenza nominale (senza slittamento), per la regola «entro il 16 dicembre». */
    public static function nominalDue(int $year, int $period, string $periodicity, ?string $qtype): string
    {
        $r = self::for($year) ?? self::for(2026);
        if ($periodicity === 'mensile') {
            return $period < 12 ? sprintf('%04d-%02d-%02d', $year, $period + 1, $r['due']['monthly_day']) : sprintf('%04d-01-%02d', $year + 1, $r['due']['monthly_day']);
        }
        if ($period <= 3) {
            return $year.'-'.$r['due']['quarterly'][$period];
        }

        return ($year + 1).'-'.($qtype === 'speciale' ? $r['due']['q4_special'] : $r['due']['q4_option']);
    }

    public static function dueDate(int $year, int $period, string $periodicity, ?string $qtype): string
    {
        return self::shift(self::nominalDue($year, $period, $periodicity, $qtype));
    }

    /** 1–20 agosto → 20 agosto; sabato, domenica, festivo → primo giorno lavorativo successivo. */
    public static function shift(string $iso): string
    {
        $t = strtotime($iso);
        if (date('m', $t) === '08' && (int) date('d', $t) <= 20) {
            $t = strtotime(date('Y', $t).'-08-20');
        }
        while (in_array((int) date('N', $t), [6, 7], true) || self::holiday(date('Y-m-d', $t))) {
            $t = strtotime('+1 day', $t);
        }

        return date('Y-m-d', $t);
    }

    public static function holiday(string $iso): bool
    {
        $y = (int) substr($iso, 0, 4);
        $fixed = ['01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'];
        $easterMonday = date('Y-m-d', easter_date_safe($y) + 86400);

        return in_array(substr($iso, 5), $fixed, true) || $iso === $easterMonday;
    }

    public static function trDeadline(int $year, int $quarter): ?string
    {
        $r = self::for($year);

        return $r && isset($r['tr_deadlines'][$quarter]) ? self::shift($year.'-'.$r['tr_deadlines'][$quarter]) : null;
    }

    /** Codice F24 della liquidazione e rateazione/anno. */
    public static function code(int $year, int $period, string $periodicity, ?string $qtype): array
    {
        $c = (self::for($year) ?? self::for(2026))['codes'];
        if ($periodicity === 'mensile') {
            return ['code' => $c['monthly'][$period], 'rateazione' => null, 'label' => 'IVA mensile'];
        }
        if ($period <= 3) {
            return ['code' => $c['quarterly'][$period], 'rateazione' => null, 'label' => 'IVA '.$period.'° trimestre'];
        }

        return $qtype === 'speciale' ? ['code' => $c['special_q4'], 'rateazione' => null, 'label' => 'IVA 4° trimestre (trimestrali speciali)']
            : ['code' => $c['annual'], 'rateazione' => '0101', 'label' => 'IVA saldo annuale (4° trimestre per opzione)'];
    }
}

/** Domenica di Pasqua (Gauss/Meeus), indipendente da ext-calendar. */
function easter_date_safe(int $y): int
{
    $a = $y % 19;
    $b = intdiv($y, 100);
    $c = $y % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;

    return strtotime(sprintf('%04d-%02d-%02d', $y, $month, $day));
}
