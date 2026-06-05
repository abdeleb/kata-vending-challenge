<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Money;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;

final class CoinSetTest extends TestCase
{
    public function test_empty_set_has_no_coins_and_zero_total(): void
    {
        $set = CoinSet::empty();

        self::assertTrue($set->isEmpty());
        self::assertSame(0, $set->count(Coin::TWENTY_FIVE));
        self::assertTrue($set->total()->equals(Money::zero()));
    }

    public function test_adding_coins_is_immutable_and_accumulates(): void
    {
        $empty = CoinSet::empty();

        $set = $empty->add(Coin::TWENTY_FIVE)->add(Coin::TWENTY_FIVE)->add(Coin::TEN);

        self::assertSame(2, $set->count(Coin::TWENTY_FIVE));
        self::assertSame(1, $set->count(Coin::TEN));
        self::assertTrue($empty->isEmpty(), 'original set must be untouched');
    }

    public function test_add_accepts_a_count(): void
    {
        $set = CoinSet::empty()->add(Coin::FIVE, 3);

        self::assertSame(3, $set->count(Coin::FIVE));
    }

    public function test_total_sums_denominations_in_cents(): void
    {
        // 1x100 + 2x25 + 1x5 = 155
        $set = CoinSet::empty()->add(Coin::HUNDRED)->add(Coin::TWENTY_FIVE, 2)->add(Coin::FIVE);

        self::assertSame(155, $set->total()->cents);
    }

    public function test_removing_coins_decrements_and_is_immutable(): void
    {
        $three = CoinSet::empty()->add(Coin::TEN, 3);

        $two = $three->remove(Coin::TEN);

        self::assertSame(2, $two->count(Coin::TEN));
        self::assertSame(3, $three->count(Coin::TEN), 'original set must be untouched');
    }

    public function test_removing_the_last_coin_empties_the_denomination(): void
    {
        $set = CoinSet::empty()->add(Coin::FIVE)->remove(Coin::FIVE);

        self::assertTrue($set->isEmpty());
    }

    public function test_removing_more_than_available_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CoinSet::empty()->add(Coin::TEN, 1)->remove(Coin::TEN, 2);
    }

    public function test_plus_merges_two_sets_and_is_immutable(): void
    {
        // The sale pours the session tray into the bank: bank + session, without mutating either.
        $bank = CoinSet::empty()->add(Coin::TWENTY_FIVE)->add(Coin::TEN, 2);
        $session = CoinSet::empty()->add(Coin::TWENTY_FIVE)->add(Coin::FIVE, 3);

        $merged = $bank->plus($session);

        self::assertSame(2, $merged->count(Coin::TWENTY_FIVE));
        self::assertSame(2, $merged->count(Coin::TEN));
        self::assertSame(3, $merged->count(Coin::FIVE));
        self::assertSame(1, $bank->count(Coin::TWENTY_FIVE), 'operands must be untouched');
        self::assertSame(0, $bank->count(Coin::FIVE), 'operands must be untouched');
    }

    public function test_plus_empty_is_identity(): void
    {
        $set = CoinSet::empty()->add(Coin::TEN, 2);

        self::assertTrue($set->plus(CoinSet::empty())->total()->equals(Money::fromCents(20)));
    }

    public function test_minus_subtracts_one_set_from_another_and_is_immutable(): void
    {
        // Committing a sale: tentative bank minus the dispensed change.
        $tentative = CoinSet::empty()->add(Coin::TWENTY_FIVE, 2)->add(Coin::TEN, 3);
        $change = CoinSet::empty()->add(Coin::TWENTY_FIVE)->add(Coin::TEN);

        $remaining = $tentative->minus($change);

        self::assertSame(1, $remaining->count(Coin::TWENTY_FIVE));
        self::assertSame(2, $remaining->count(Coin::TEN));
        self::assertSame(2, $tentative->count(Coin::TWENTY_FIVE), 'operands must be untouched');
    }

    public function test_minus_drops_a_fully_removed_denomination(): void
    {
        $set = CoinSet::empty()->add(Coin::FIVE, 2);

        self::assertTrue($set->minus($set)->isEmpty());
    }

    public function test_minus_more_than_available_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CoinSet::empty()->add(Coin::TEN)->minus(CoinSet::empty()->add(Coin::TEN, 2));
    }
}
