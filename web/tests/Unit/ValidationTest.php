<?php

namespace Tests\Unit;

use App\Support\Validation\CodiceFiscale;
use App\Support\Validation\Iban;
use App\Support\Validation\PartitaIva;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    public function test_partita_iva_check_digit(): void
    {
        // Cifra di controllo calcolata a mano, indipendentemente dall'implementazione:
        // 0123456789: posizioni dispari 0+2+4+6+8=20, posizioni pari raddoppiate 2,6,1(10-9),5(14-9),9(18-9)=23 → 43 → (10-3)%10 = 7
        $this->assertSame(7, PartitaIva::checkDigit('0123456789'));
        $this->assertTrue(PartitaIva::isValid('01234567897'));
        $this->assertFalse(PartitaIva::isValid('01234567890'));
        $this->assertFalse(PartitaIva::isValid('0123456789'));
        $this->assertFalse(PartitaIva::isValid('0123456789A'));
        $this->assertTrue(PartitaIva::isValid(PartitaIva::complete('9876543210')));
    }

    public function test_iban_mod97(): void
    {
        // IBAN di esempio dalla documentazione della Banca d'Italia/esempi ECBS.
        $this->assertTrue(Iban::isValid('IT60X0542811101000000123456'));
        $this->assertTrue(Iban::isValid('it60 x054 2811 1010 0000 0123 456'));
        $this->assertFalse(Iban::isValid('IT61X0542811101000000123456'));
        $this->assertFalse(Iban::isValid('IT60X054281110100000012345'), 'IT richiede 27 caratteri');
        $this->assertTrue(Iban::isValid('DE89370400440532013000'));
        $this->assertSame(['abi' => '05428', 'cab' => '11101'], Iban::abiCab('IT60X0542811101000000123456'));
    }

    public function test_codice_fiscale_check_character(): void
    {
        // Calcolato a mano: RSSMRA85T10A562 → posizioni dispari R,S,R,8,T,0,5,2 = 8+12+8+19+14+1+13+5 = 80,
        // posizioni pari S,M,A,5,1,A,6 = 18+12+0+5+1+0+6 = 42 → 122 mod 26 = 18 → «S».
        $this->assertSame('S', CodiceFiscale::checkChar('RSSMRA85T10A562'));
        $this->assertTrue(CodiceFiscale::isValid('RSSMRA85T10A562S'));
        $this->assertTrue(CodiceFiscale::isValid('rssmra85t10a562s'));
        $this->assertFalse(CodiceFiscale::isValid('RSSMRA85T10A562T'));
        $this->assertFalse(CodiceFiscale::isValid('RSSMRA85Z10A562S'), 'Z non è una lettera del mese valida');
        // Omocodia: cifre sostituite da lettere L–V, carattere di controllo ricalcolato.
        $this->assertTrue(CodiceFiscale::isValid(CodiceFiscale::complete('RSSMRA85T10A56N')));
        // 11 cifre: stessa cifra di controllo della partita IVA.
        $this->assertTrue(CodiceFiscale::isValid('01234567897'));
        $this->assertFalse(CodiceFiscale::isValid('01234567890'));
    }
}
