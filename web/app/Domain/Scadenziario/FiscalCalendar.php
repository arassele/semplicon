<?php

namespace App\Domain\Scadenziario;

use Carbon\CarbonImmutable;

/**
 * Calendario fiscale (indicazione del committente, 21.11.2026, per 2027, 2028 e seguenti): scadenze ordinarie di un anno solare, generate in base a regole.
 * Se una scadenza cade di sabato, domenica o in un giorno festivo, vale il primo giorno lavorativo successivo (art. 7 DL 70/2011; `from` mostra la data originaria).
 * Sono le scadenze ordinarie senza proroghe, che possono essere modificate ogni anno per legge o decreto: verificare sempre alla fonte.
 *
 * Tipi: iva (versamenti IVA), lipe (comunicazione liquidazioni periodiche), dichiarazione (dichiarazione IVA), bollo (bollo fatture elettroniche), xb (operazioni transfrontaliere).
 */
final class FiscalCalendar
{
    private const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    public const TYPES = ['iva' => 'Versamenti IVA', 'dichiarazione' => 'Dichiarazione IVA', 'lipe' => 'LIPE', 'bollo' => 'Bollo fatture elettroniche', 'xb' => 'Operazioni transfrontaliere'];

    /** @return list<array{date: string, from: string|null, type: string, label: string, code: string|null, note: string|null}> */
    public static function forYear(int $year, ?string $periodicity): array
    {
        $out = [];
        $add = function (string $type, string $label, int $y, int $m, int $d, ?string $code = null, ?string $note = null) use (&$out) {
            $raw = CarbonImmutable::create($y, $m, min($d, CarbonImmutable::create($y, $m, 1)->daysInMonth));
            $eff = self::workday($raw);
            $out[] = ['date' => $eff->toDateString(), 'from' => $eff->equalTo($raw) ? null : $raw->toDateString(), 'type' => $type, 'label' => $label, 'code' => $code, 'note' => $note];
        };
        $prev = $year - 1;
        $mon = fn (int $m, int $y) => self::MONTHS[$m - 1].' '.$y;

        // IVA: versamenti periodici
        if ($periodicity === 'mensile') {
            foreach (range(1, 12) as $m) {
                [$pm, $py] = $m === 1 ? [12, $prev] : [$m - 1, $year];
                $add('iva', 'Versamento IVA di '.$mon($pm, $py), $year, $m, 16, sprintf('60%02d', $pm), 'Liquidazione mensile; codice tributo 6001–6012 secondo il mese.');
            }
            $add('iva', 'Acconto IVA '.$year, $year, 12, 27, '6013', 'Acconto IVA mensili (codice 6013).');
        } elseif ($periodicity === 'trimestrale') {
            $add('iva', 'Versamento IVA 1° trimestre '.$year, $year, 5, 16, '6031');
            $add('iva', 'Versamento IVA 2° trimestre '.$year, $year, 8, 20, '6032', 'Il 16 agosto è prorogato al 20 agosto.');
            $add('iva', 'Versamento IVA 3° trimestre '.$year, $year, 11, 16, '6033');
            $add('iva', 'Acconto IVA '.$year, $year, 12, 27, '6035', 'Acconto IVA trimestrali (codice 6035).');
        }
        if ($periodicity) {
            $add('iva', "Versamento saldo IVA {$prev}", $year, 3, 16, '6099', 'Saldo annuale (comprende il 4° trimestre per i trimestrali per opzione). Oltre il 16 marzo e fino al 30 giugno con maggiorazione dello 0,40% per mese.');
        }
        $add('dichiarazione', "Dichiarazione IVA {$prev}: invio telematico", $year, 4, 30, null, "Dichiarazione annuale IVA relativa all'anno {$prev}.");

        // LIPE
        $add('lipe', "LIPE 4° trimestre {$prev}", $year, 2, 29, null, 'Ultimo giorno di febbraio.');
        $add('lipe', "LIPE 1° trimestre {$year}", $year, 5, 31);
        $add('lipe', "LIPE 2° trimestre {$year}", $year, 9, 16);
        $add('lipe', "LIPE 3° trimestre {$year}", $year, 11, 30);

        // Bollo sulle fatture elettroniche
        $add('bollo', "Bollo fatture elettroniche 4° trimestre {$prev}", $year, 2, 29, null, 'Ultimo giorno di febbraio.');
        $add('bollo', "Bollo fatture elettroniche 1° trimestre {$year}", $year, 5, 31, null, 'Se l’imposta del 1° trimestre non supera 5.000 € il versamento può essere rinviato al termine del 2° trimestre (30 settembre).');
        $add('bollo', "Bollo fatture elettroniche 2° trimestre {$year}", $year, 9, 30, null, 'Se l’imposta dei primi due trimestri non supera 5.000 € il versamento può essere rinviato al 30 novembre.');
        $add('bollo', "Bollo fatture elettroniche 3° trimestre {$year}", $year, 11, 30);

        // Operazioni transfrontaliere: acquisti entro il 15 del mese successivo (vendite: entro 12 giorni dalla data del documento)
        foreach (range(1, 12) as $m) {
            [$pm, $py] = $m === 1 ? [12, $prev] : [$m - 1, $year];
            $add('xb', 'Invio operazioni transfrontaliere: acquisti di '.$mon($pm, $py), $year, $m, 15, null, 'TD17–TD19 e TD28 entro il 15 del mese successivo (vendite all’estero: 12 giorni).');
        }
        usort($out, fn ($a, $b) => [$a['date'], $a['type']] <=> [$b['date'], $b['type']]);

        return $out;
    }

    /** Primo giorno lavorativo dalla data (sabato, domenica e festivi italiani vengono saltati). */
    public static function workday(CarbonImmutable $d): CarbonImmutable
    {
        while ($d->isWeekend() || in_array($d->format('m-d'), self::fixedHolidays(), true) || $d->toDateString() === self::easterMonday($d->year)->toDateString()) {
            $d = $d->addDay();
        }

        return $d;
    }

    /** @return list<string> */
    private static function fixedHolidays(): array
    {
        return ['01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'];
    }

    public static function easterMonday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
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

        return CarbonImmutable::create($year, $month, $day)->addDay();
    }
}
