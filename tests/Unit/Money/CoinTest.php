<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Money;

use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Money\Coin;

final class CoinTest extends TestCase
{
    public function test_each_coin_knows_its_value_in_cents(): void
    {
        self::assertSame(5, Coin::FIVE->valueInCents());
        self::assertSame(10, Coin::TEN->valueInCents());
        self::assertSame(25, Coin::TWENTY_FIVE->valueInCents());
        self::assertSame(100, Coin::HUNDRED->valueInCents());
    }

    public function test_the_accepted_input_denominations_are_5_10_25_and_100(): void
    {
        $cents = array_map(static fn (Coin $coin): int => $coin->valueInCents(), Coin::cases());

        self::assertSame([5, 10, 25, 100], $cents);
    }

    public function test_5_10_and_25_are_dispensable_as_change(): void
    {
        self::assertTrue(Coin::FIVE->isDispensableAsChange());
        self::assertTrue(Coin::TEN->isDispensableAsChange());
        self::assertTrue(Coin::TWENTY_FIVE->isDispensableAsChange());
    }

    public function test_the_100_cent_coin_is_accepted_but_never_returned_as_change(): void
    {
        // Grounded in the spec's valid responses: "0.05, 0.10, 0.25 - return coin".
        // The 1.00 coin pays into the bank but is never dispensed as change.
        self::assertFalse(Coin::HUNDRED->isDispensableAsChange());
    }
}
