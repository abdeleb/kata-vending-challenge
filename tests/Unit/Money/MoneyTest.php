<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Money;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Money\Money;

final class MoneyTest extends TestCase
{
    public function test_exposes_its_amount_in_cents(): void
    {
        self::assertSame(65, Money::fromCents(65)->cents);
    }

    public function test_zero_is_zero_cents(): void
    {
        self::assertSame(0, Money::zero()->cents);
    }

    public function test_addition_returns_a_new_summed_money_and_leaves_operands_untouched(): void
    {
        $a = Money::fromCents(25);
        $b = Money::fromCents(10);

        $sum = $a->add($b);

        self::assertSame(35, $sum->cents);
        self::assertSame(25, $a->cents, 'left operand must be immutable');
        self::assertSame(10, $b->cents, 'right operand must be immutable');
    }

    public function test_subtraction_returns_the_difference(): void
    {
        self::assertSame(40, Money::fromCents(100)->subtract(Money::fromCents(60))->cents);
    }

    public function test_construction_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromCents(-1);
    }

    public function test_subtraction_below_zero_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromCents(10)->subtract(Money::fromCents(25));
    }

    public function test_equality_is_by_value(): void
    {
        self::assertTrue(Money::fromCents(50)->equals(Money::fromCents(50)));
        self::assertFalse(Money::fromCents(50)->equals(Money::fromCents(51)));
    }

    public function test_greater_than_or_equal_supports_the_funds_check(): void
    {
        self::assertTrue(Money::fromCents(150)->isGreaterThanOrEqualTo(Money::fromCents(150)));
        self::assertTrue(Money::fromCents(151)->isGreaterThanOrEqualTo(Money::fromCents(150)));
        self::assertFalse(Money::fromCents(149)->isGreaterThanOrEqualTo(Money::fromCents(150)));
    }
}
