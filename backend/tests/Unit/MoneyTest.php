<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_adds_and_subtracts_in_minor_units(): void
    {
        $a = Money::ofMinor(1050, 'HTG');
        $b = Money::ofMinor(250, 'HTG');

        $this->assertSame(1300, $a->plus($b)->minor);
        $this->assertSame(800, $a->minus($b)->minor);
        $this->assertTrue($b->minus($a)->isNegative());
    }

    public function test_refuses_mixing_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor(100, 'HTG')->plus(Money::ofMinor(100, 'USD'));
    }

    public function test_refuses_invalid_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor(100, 'gourde');
    }
}
