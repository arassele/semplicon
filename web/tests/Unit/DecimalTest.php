<?php

namespace Tests\Unit;

use App\Support\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_conversion_without_float(): void
    {
        $this->assertSame(1230, Decimal::toMinor('12.3'));
        $this->assertSame(-5, Decimal::toMinor('-0.05'));
        $this->assertSame('12.30', Decimal::fromMinor(1230));
        $this->assertSame('-0.05', Decimal::fromMinor(-5));
        $this->assertSame('5.00', Decimal::normalize('5'));
        $this->assertSame('99999999999.99', Decimal::normalize('99999999999.99'));
        // 0.1 + 0.2 è esattamente 0.30, diversamente dal float.
        $this->assertSame('0.30', Decimal::fromMinor(Decimal::toMinor('0.1') + Decimal::toMinor('0.2')));
    }

    public function test_too_many_decimals_are_rejected_not_rounded(): void
    {
        $this->assertSame(1230, Decimal::toMinor('12.3000'));
        $this->expectException(InvalidArgumentException::class);
        Decimal::toMinor('12.345');
    }
}
