<?php

namespace Tests\Unit;

use App\Support\Money;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_arithmetic_is_exact_with_two_decimals(): void
    {
        // 0.1 + 0.2 is the classic float trap.
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('1349.97', Money::mul('449.99', 3));
        $this->assertSame('9.90', Money::sub('10.00', '0.10'));
    }

    public function test_percent_off_is_rounded_to_a_whole_number(): void
    {
        $this->assertSame(11, Money::percentOff('720.00', '640.00'));
        $this->assertSame(0, Money::percentOff('100.00', '100.00'));
        $this->assertSame(0, Money::percentOff('0.00', '0.00'));
        $this->assertSame(0, Money::percentOff('100.00', '120.00'));
    }

    public function test_format_shows_currency_and_two_decimals(): void
    {
        $this->assertSame('Le 1,250.00', Money::format('1250'));
        $this->assertSame('Le 0.00', Money::format(null));
    }
}
