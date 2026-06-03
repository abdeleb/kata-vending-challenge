<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Change;

use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Change\BacktrackingChangeStrategy;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;

final class BacktrackingChangeStrategyTest extends TestCase
{
    public function test_zero_change_due_returns_an_empty_coin_set_not_null(): void
    {
        $strategy = new BacktrackingChangeStrategy();

        $change = $strategy->computeChange(Money::zero(), CoinSet::empty());

        self::assertNotNull($change, 'exact payment is a valid sale (zero change), not an impossible one');
        self::assertTrue($change->isEmpty());
    }

    public function test_composes_change_from_the_available_coins(): void
    {
        $available = CoinSet::empty()->add(Coin::TWENTY_FIVE, 4)->add(Coin::TEN, 4);
        $strategy = new BacktrackingChangeStrategy();

        $change = $strategy->computeChange(Money::fromCents(45), $available);

        self::assertNotNull($change);
        self::assertTrue($change->total()->equals(Money::fromCents(45)));
    }

    public function test_finds_change_where_a_greedy_pick_would_strand_itself(): void
    {
        // 30c from [25x1, 10x3]: a greedy pick takes the 25, then cannot make the
        // remaining 5 (no 5c coins) and gives up. The only feasible breakdown is 10+10+10.
        $available = CoinSet::empty()->add(Coin::TWENTY_FIVE, 1)->add(Coin::TEN, 3);
        $strategy = new BacktrackingChangeStrategy();

        $change = $strategy->computeChange(Money::fromCents(30), $available);

        self::assertNotNull($change);
        self::assertSame(3, $change->count(Coin::TEN));
        self::assertSame(0, $change->count(Coin::TWENTY_FIVE));
        self::assertSame(0, $change->count(Coin::FIVE));
        self::assertTrue($change->total()->equals(Money::fromCents(30)));
    }

    public function test_fails_closed_when_no_combination_exists(): void
    {
        // 5c owed but only 10c coins on hand: no subset sums to 5 -> change is impossible.
        $available = CoinSet::empty()->add(Coin::TEN, 5);
        $strategy = new BacktrackingChangeStrategy();

        self::assertNull($strategy->computeChange(Money::fromCents(5), $available));
    }

    public function test_never_dispenses_the_hundred_cent_coin_as_change(): void
    {
        // 100c owed and the only coin on hand is the 100c coin. It is accepted as
        // payment but is never returned as change, so no valid change exists -> null.
        $available = CoinSet::empty()->add(Coin::HUNDRED, 1);
        $strategy = new BacktrackingChangeStrategy();

        self::assertNull($strategy->computeChange(Money::fromCents(100), $available));
    }

    public function test_uses_only_dispensable_coins_even_when_a_hundred_would_fit(): void
    {
        // The 100c coin is present but must be ignored; 50c is composed from 25 + 25.
        $available = CoinSet::empty()->add(Coin::HUNDRED, 1)->add(Coin::TWENTY_FIVE, 2);
        $strategy = new BacktrackingChangeStrategy();

        $change = $strategy->computeChange(Money::fromCents(50), $available);

        self::assertNotNull($change);
        self::assertSame(0, $change->count(Coin::HUNDRED));
        self::assertSame(2, $change->count(Coin::TWENTY_FIVE));
        self::assertTrue($change->total()->equals(Money::fromCents(50)));
    }
}
