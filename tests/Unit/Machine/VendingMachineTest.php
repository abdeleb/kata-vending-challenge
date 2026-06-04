<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Machine;

use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Machine\OperationalMode;
use VendingMachine\Domain\Machine\VendingMachine;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\Money;

final class VendingMachineTest extends TestCase
{
    public function test_a_fresh_machine_starts_operational_with_an_empty_session(): void
    {
        $machine = VendingMachine::operational();

        self::assertSame(OperationalMode::Operational, $machine->mode());
        self::assertTrue($machine->insertedAmount()->equals(Money::zero()));
    }

    public function test_inserted_coins_accumulate_as_the_current_balance(): void
    {
        $machine = VendingMachine::operational();

        $machine->insertCoin(Coin::TWENTY_FIVE);
        $machine->insertCoin(Coin::TEN);

        self::assertTrue($machine->insertedAmount()->equals(Money::fromCents(35)));
    }

    public function test_return_coin_gives_back_exactly_what_was_inserted_and_empties_the_session(): void
    {
        // Spec example 2: 0.10, 0.10, RETURN-COIN -> 0.10, 0.10 (the very coins inserted).
        $machine = VendingMachine::operational();
        $machine->insertCoin(Coin::TEN);
        $machine->insertCoin(Coin::TEN);

        $returned = $machine->returnCoins();

        self::assertSame(2, $returned->count(Coin::TEN));
        self::assertTrue($returned->total()->equals(Money::fromCents(20)));
        self::assertTrue($machine->insertedAmount()->equals(Money::zero()), 'the retention tray is emptied');
    }

    public function test_return_coin_with_no_balance_is_a_harmless_no_op(): void
    {
        self::assertTrue(VendingMachine::operational()->returnCoins()->isEmpty());
    }
}
