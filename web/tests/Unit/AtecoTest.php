<?php

namespace Tests\Unit;

use App\Support\Ateco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Verifica l'elenco ISTAT ATECO 2025 integrato rispetto a voci note del file sorgente. */
class AtecoTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_contains_official_six_digit_codes(): void
    {
        $all = Ateco::all();
        $this->assertCount(1290, $all);
        foreach (array_keys($all) as $code) {
            $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{2}$/', $code);
        }
        $this->assertSame('Attività di commercialisti', Ateco::find('69.20.01')['title_it']);
        $this->assertSame('Attività di programmazione informatica', Ateco::find('62.10.00')['title_it']);
    }

    public function test_old_ateco_2007_codes_are_not_accepted(): void
    {
        $this->assertNull(Ateco::find('69.20.11'));
        $this->assertNull(Ateco::find('74.10.21'));
    }
}
