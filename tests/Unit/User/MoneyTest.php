<?php

namespace Tests\Unit\User;

use App\User\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_parses_and_rounds_decimal_values_to_integer_cents(): void
    {
        $this->assertSame('0.00', Money::from('0')->toString());
        $this->assertSame('1.01', Money::from('1.005')->toString());
        $this->assertSame('-1.01', Money::from('-1.005')->toString());
        $this->assertSame('12.30', Money::from(12.3)->toString());
    }

    public function test_it_adds_and_subtracts_without_floating_point_drift(): void
    {
        $sum = Money::from('0.10')->add(Money::from('0.20'));
        $difference = $sum->subtract(Money::from('0.05'));

        $this->assertSame('0.30', $sum->toString());
        $this->assertSame('0.25', $difference->toString());
        $this->assertSame(1, $difference->compareTo(Money::from('0.24')));
    }

    public function test_it_multiplies_by_a_four_decimal_commission_rate(): void
    {
        $this->assertSame('12.35', Money::from('123.45')->multiplyRate('0.1000')->toString());
        $this->assertSame('0.01', Money::from('0.05')->multiplyRate('0.1000')->toString());
    }

    public function test_it_rejects_invalid_money_and_rate_values(): void
    {
        foreach (['', 'not-money', '1.2.3'] as $invalid) {
            try {
                Money::from($invalid);
                $this->fail("Expected [{$invalid}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        Money::from('10.00')->multiplyRate('1.0001');
    }
}
