<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Machine;

use LogicException;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Exception\DomainException;
use VendingMachine\Domain\Exception\IllegalState;
use VendingMachine\Domain\Exception\SessionNotEmpty;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\OperationalMode;
use VendingMachine\Domain\Machine\VendingMachine;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
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

    public function test_entering_service_switches_mode_when_the_session_is_empty(): void
    {
        $machine = VendingMachine::operational();

        $machine->enterService();

        self::assertSame(OperationalMode::Service, $machine->mode());
    }

    public function test_leaving_service_returns_the_machine_to_operational(): void
    {
        $machine = VendingMachine::operational();
        $machine->enterService();

        $machine->leaveService();

        self::assertSame(OperationalMode::Operational, $machine->mode());
    }

    public function test_entering_service_with_coins_in_the_tray_is_refused_and_leaves_state_intact(): void
    {
        $machine = VendingMachine::operational();
        $machine->insertCoin(Coin::TWENTY_FIVE);

        try {
            $machine->enterService();
            self::fail('Expected SessionNotEmpty');
        } catch (SessionNotEmpty) {
            // The transition is rejected without side effects: still Operational, coins untouched.
            self::assertSame(OperationalMode::Operational, $machine->mode());
            self::assertTrue($machine->insertedAmount()->equals(Money::fromCents(25)));
        }
    }

    public function test_entering_service_with_coins_is_a_recoverable_domain_error(): void
    {
        // The CLI catches the whole DomainException category and maps it to a user message; this
        // pins SessionNotEmpty into that recoverable channel (vs. the unmapped IllegalState bug).
        $machine = VendingMachine::operational();
        $machine->insertCoin(Coin::TWENTY_FIVE);

        $this->expectException(DomainException::class);
        $machine->enterService();
    }

    public function test_returning_the_coins_then_entering_service_is_the_documented_recovery(): void
    {
        $machine = VendingMachine::operational();
        $machine->insertCoin(Coin::TWENTY_FIVE);

        $machine->returnCoins();
        $machine->enterService();

        self::assertSame(OperationalMode::Service, $machine->mode());
    }

    public function test_inserting_a_coin_in_service_mode_is_an_illegal_state(): void
    {
        $machine = VendingMachine::operational();
        $machine->enterService();

        $this->expectException(IllegalState::class);
        $machine->insertCoin(Coin::TEN);
    }

    public function test_returning_coins_in_service_mode_is_an_illegal_state(): void
    {
        $machine = VendingMachine::operational();
        $machine->enterService();

        $this->expectException(IllegalState::class);
        $machine->returnCoins();
    }

    public function test_entering_service_twice_is_an_illegal_state(): void
    {
        $machine = VendingMachine::operational();
        $machine->enterService();

        $this->expectException(IllegalState::class);
        $machine->enterService();
    }

    public function test_leaving_service_while_operational_is_an_illegal_state(): void
    {
        $this->expectException(IllegalState::class);
        VendingMachine::operational()->leaveService();
    }

    public function test_a_mode_violation_is_a_programming_bug_not_a_recoverable_domain_error(): void
    {
        // IllegalState is a LogicException that bubbles unmapped — the CLI never translates it,
        // unlike the recoverable DomainException category. LogicException and RuntimeException are
        // disjoint branches, so catching it here as a LogicException proves it is not a DomainException.
        $machine = VendingMachine::operational();
        $machine->enterService();

        $this->expectException(LogicException::class);
        $machine->insertCoin(Coin::TEN);
    }

    public function test_a_fresh_machine_holds_no_change_and_no_stock(): void
    {
        $machine = VendingMachine::operational();

        self::assertTrue($machine->availableChange()->isEmpty());
        self::assertSame(0, $machine->stockOf('WATER'));
    }

    public function test_service_sets_the_available_change(): void
    {
        $machine = VendingMachine::operational();
        $machine->enterService();

        $machine->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 4)->add(Coin::TEN, 2));

        self::assertSame(4, $machine->availableChange()->count(Coin::TWENTY_FIVE));
        self::assertSame(2, $machine->availableChange()->count(Coin::TEN));
        self::assertTrue($machine->availableChange()->total()->equals(Money::fromCents(120)));
    }

    public function test_setting_the_change_replaces_the_reserve_rather_than_adding_to_it(): void
    {
        // Verbatim "set the available change": the technician declares the absolute coin inventory,
        // not an increment. SERVICE is the deliberate point where money conservation does not hold.
        $machine = VendingMachine::operational();
        $machine->enterService();

        $machine->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 10));
        $machine->setAvailableChange(CoinSet::empty()->add(Coin::FIVE, 3));

        self::assertSame(0, $machine->availableChange()->count(Coin::TWENTY_FIVE), 'the previous reserve is replaced, not topped up');
        self::assertSame(3, $machine->availableChange()->count(Coin::FIVE));
    }

    public function test_service_restocks_the_items(): void
    {
        $machine = VendingMachine::operational();
        $machine->enterService();

        $machine->restockItems(ItemInventory::fromQuantities(['WATER' => 5, 'SODA' => 2]));

        self::assertSame(5, $machine->stockOf('WATER'));
        self::assertSame(2, $machine->stockOf('SODA'));
    }

    public function test_restocking_replaces_the_stock_rather_than_adding_to_it(): void
    {
        $machine = VendingMachine::operational();
        $machine->enterService();

        $machine->restockItems(ItemInventory::fromQuantities(['WATER' => 5]));
        $machine->restockItems(ItemInventory::fromQuantities(['SODA' => 2]));

        self::assertSame(0, $machine->stockOf('WATER'), 'the previous stock is replaced, not topped up');
        self::assertSame(2, $machine->stockOf('SODA'));
    }

    public function test_setting_the_change_is_a_service_only_action(): void
    {
        $this->expectException(IllegalState::class);
        VendingMachine::operational()->setAvailableChange(CoinSet::empty()->add(Coin::FIVE));
    }

    public function test_restocking_is_a_service_only_action(): void
    {
        $this->expectException(IllegalState::class);
        VendingMachine::operational()->restockItems(ItemInventory::fromQuantities(['WATER' => 1]));
    }
}
