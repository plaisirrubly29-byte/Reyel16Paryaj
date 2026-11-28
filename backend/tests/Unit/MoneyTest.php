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

    /** Sans l'extension intl (#280) : espaces insécables entre les milliers et avant la devise. */
    public function test_formats_in_french_without_intl(): void
    {
        $nbsp = "\u{00A0}";

        $this->assertSame("1{$nbsp}500,00{$nbsp}HTG", Money::ofMinor(1_500_00, 'HTG')->format());
        $this->assertSame("0,05{$nbsp}HTG", Money::ofMinor(5, 'HTG')->format());
        $this->assertSame("0,00{$nbsp}HTG", Money::zero('HTG')->format());
        $this->assertSame("1{$nbsp}234{$nbsp}567,89{$nbsp}USD", Money::ofMinor(123_456_789, 'USD')->format());
        $this->assertSame("-2{$nbsp}050,10{$nbsp}HTG", Money::ofMinor(-205_010, 'HTG')->format());
    }
}
