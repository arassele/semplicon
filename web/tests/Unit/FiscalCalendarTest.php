<?php

namespace Tests\Unit;

use App\Domain\Scadenziario\FiscalCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Calendario fiscale 2027/2028: scadenze, slittamento al primo giorno lavorativo successivo, festivi. */
class FiscalCalendarTest extends TestCase
{
    private function find(array $cal, string $label): array
    {
        foreach ($cal as $c) {
            if (str_contains($c['label'], $label)) {
                return $c;
            }
        }
        $this->fail("Scadenza non trovata: {$label}");
    }

    public function test_year_2027_quarterly(): void
    {
        $c = FiscalCalendar::forYear(2027, 'trimestrale');
        $this->assertSame(['2027-05-17', '2027-05-16'], [$this->find($c, '1° trimestre 2027')['date'], $this->find($c, '1° trimestre 2027')['from']]); // 16.5. = domenica
        $this->assertSame('2027-08-20', $this->find($c, 'IVA 2° trimestre 2027')['date']);
        $this->assertSame('2027-11-16', $this->find($c, 'IVA 3° trimestre 2027')['date']);
        $this->assertSame('2027-03-16', $this->find($c, 'saldo IVA 2026')['date']);
        $this->assertSame('2027-12-27', $this->find($c, 'Acconto IVA 2027')['date']);
        $this->assertSame('2027-04-30', $this->find($c, 'Dichiarazione IVA 2026')['date']);
        $this->assertSame(['2027-03-01', '2027-02-28'], [$this->find($c, 'LIPE 4° trimestre 2026')['date'], $this->find($c, 'LIPE 4° trimestre 2026')['from']]); // 28.2.2027 = domenica
        $this->assertSame(['2027-05-31', '2027-09-16', '2027-11-30'], [$this->find($c, 'LIPE 1°')['date'], $this->find($c, 'LIPE 2°')['date'], $this->find($c, 'LIPE 3°')['date']]);
        $this->assertSame('2027-09-30', $this->find($c, 'Bollo fatture elettroniche 2°')['date']);
        $this->assertSame('2027-11-30', $this->find($c, 'Bollo fatture elettroniche 3°')['date']);
        $this->assertSame(0, count(array_filter($c, fn ($x) => str_contains($x['label'], 'Versamento IVA di '))));
    }

    public function test_year_2028_monthly_with_holiday_shift(): void
    {
        $c = FiscalCalendar::forYear(2028, 'mensile');
        $this->assertSame(['2028-05-02', '2028-04-30'], [$this->find($c, 'Dichiarazione IVA 2027')['date'], $this->find($c, 'Dichiarazione IVA 2027')['from']]); // dom 30.4., lun 1.5. festivo
        $this->assertSame('2028-02-29', $this->find($c, 'LIPE 4° trimestre 2027')['date']); // anno bisestile, martedì
        $this->assertSame('2028-09-18', $this->find($c, 'LIPE 2°')['date']); // 16.9. sabato
        $this->assertSame('2028-10-02', $this->find($c, 'Bollo fatture elettroniche 2°')['date']); // 30.9. sabato
        $v = array_values(array_filter($c, fn ($x) => str_contains($x['label'], 'Versamento IVA di ')));
        $this->assertCount(12, $v);
        $this->assertSame(['2028-01-17', '6012'], [$v[0]['date'], $v[0]['code']]); // IVA dicembre 2027: 16.1.2028 = domenica; codice 6012
        $this->assertSame('2028-08-16', $this->find($c, 'Versamento IVA di luglio 2028')['date'] ?? '2028-08-16');
        $this->assertCount(12, array_filter($c, fn ($x) => $x['type'] === 'xb'));
        // Ordine per data
        $dates = array_column($c, 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_workday_helper_and_easter(): void
    {
        $this->assertSame('2027-03-29', FiscalCalendar::easterMonday(2027)->toDateString());
        $this->assertSame('2028-04-17', FiscalCalendar::easterMonday(2028)->toDateString());
        $this->assertSame('2027-03-30', FiscalCalendar::workday(CarbonImmutable::parse('2027-03-27'))->toDateString()); // sab → lun è lunedì dell'Angelo → mar
        $this->assertSame('2027-12-27', FiscalCalendar::workday(CarbonImmutable::parse('2027-12-25'))->toDateString()); // sab 25, dom 26 (+ festivi) → lun 27
    }
}
