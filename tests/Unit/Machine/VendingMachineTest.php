<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Machine;

use LogicException;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Change\BacktrackingChangeStrategy;
use VendingMachine\Domain\Exception\CannotDispenseChange;
use VendingMachine\Domain\Exception\DomainException;
use VendingMachine\Domain\Exception\IllegalState;
use VendingMachine\Domain\Exception\InsufficientFunds;
use VendingMachine\Domain\Exception\OutOfStock;
use VendingMachine\Domain\Exception\SessionNotEmpty;
use VendingMachine\Domain\Exception\UnknownProduct;
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
        $machine = self::machine();

        self::assertSame(OperationalMode::Operational, $machine->mode());
        self::assertTrue($machine->insertedAmount()->equals(Money::zero()));
    }

    public function test_inserted_coins_accumulate_as_the_current_balance(): void
    {
        $machine = self::machine();

        $machine->insertCoin(Coin::TWENTY_FIVE);
        $machine->insertCoin(Coin::TEN);

        self::assertTrue($machine->insertedAmount()->equals(Money::fromCents(35)));
    }

    public function test_return_coin_gives_back_exactly_what_was_inserted_and_empties_the_session(): void
    {
        // Spec example 2: 0.10, 0.10, RETURN-COIN -> 0.10, 0.10 (the very coins inserted).
        $machine = self::machine();
        $machine->insertCoin(Coin::TEN);
        $machine->insertCoin(Coin::TEN);

        $returned = $machine->returnCoins();

        self::assertSame(2, $returned->count(Coin::TEN));
        self::assertTrue($returned->total()->equals(Money::fromCents(20)));
        self::assertTrue($machine->insertedAmount()->equals(Money::zero()), 'the retention tray is emptied');
    }

    public function test_return_coin_with_no_balance_is_a_harmless_no_op(): void
    {
        self::assertTrue(self::machine()->returnCoins()->isEmpty());
    }

    public function test_entering_service_switches_mode_when_the_session_is_empty(): void
    {
        $machine = self::machine();

        $machine->enterService();

        self::assertSame(OperationalMode::Service, $machine->mode());
    }

    public function test_leaving_service_returns_the_machine_to_operational(): void
    {
        $machine = self::machine();
        $machine->enterService();

        $machine->leaveService();

        self::assertSame(OperationalMode::Operational, $machine->mode());
    }

    public function test_entering_service_with_coins_in_the_tray_is_refused_and_leaves_state_intact(): void
    {
        $machine = self::machine();
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
        $machine = self::machine();
        $machine->insertCoin(Coin::TWENTY_FIVE);

        $this->expectException(DomainException::class);
        $machine->enterService();
    }

    public function test_returning_the_coins_then_entering_service_is_the_documented_recovery(): void
    {
        $machine = self::machine();
        $machine->insertCoin(Coin::TWENTY_FIVE);

        $machine->returnCoins();
        $machine->enterService();

        self::assertSame(OperationalMode::Service, $machine->mode());
    }

    public function test_inserting_a_coin_in_service_mode_is_an_illegal_state(): void
    {
        $machine = self::machine();
        $machine->enterService();

        $this->expectException(IllegalState::class);
        $machine->insertCoin(Coin::TEN);
    }

    public function test_returning_coins_in_service_mode_is_an_illegal_state(): void
    {
        $machine = self::machine();
        $machine->enterService();

        $this->expectException(IllegalState::class);
        $machine->returnCoins();
    }

    public function test_entering_service_twice_is_an_illegal_state(): void
    {
        $machine = self::machine();
        $machine->enterService();

        $this->expectException(IllegalState::class);
        $machine->enterService();
    }

    public function test_leaving_service_while_operational_is_an_illegal_state(): void
    {
        $this->expectException(IllegalState::class);
        self::machine()->leaveService();
    }

    public function test_a_mode_violation_is_a_programming_bug_not_a_recoverable_domain_error(): void
    {
        // IllegalState is a LogicException that bubbles unmapped — the CLI never translates it,
        // unlike the recoverable DomainException category. LogicException and RuntimeException are
        // disjoint branches, so catching it here as a LogicException proves it is not a DomainException.
        $machine = self::machine();
        $machine->enterService();

        $this->expectException(LogicException::class);
        $machine->insertCoin(Coin::TEN);
    }

    public function test_a_fresh_machine_holds_no_change_and_no_stock(): void
    {
        $machine = self::machine();

        self::assertTrue($machine->availableChange()->isEmpty());
        self::assertSame(0, $machine->stockOf('WATER'));
    }

    public function test_service_sets_the_available_change(): void
    {
        $machine = self::machine();
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
        $machine = self::machine();
        $machine->enterService();

        $machine->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 10));
        $machine->setAvailableChange(CoinSet::empty()->add(Coin::FIVE, 3));

        self::assertSame(0, $machine->availableChange()->count(Coin::TWENTY_FIVE), 'the previous reserve is replaced, not topped up');
        self::assertSame(3, $machine->availableChange()->count(Coin::FIVE));
    }

    public function test_service_restocks_the_items(): void
    {
        $machine = self::machine();
        $machine->enterService();

        $machine->restockItems(ItemInventory::fromQuantities(['WATER' => 5, 'SODA' => 2]));

        self::assertSame(5, $machine->stockOf('WATER'));
        self::assertSame(2, $machine->stockOf('SODA'));
    }

    public function test_restocking_replaces_the_stock_rather_than_adding_to_it(): void
    {
        $machine = self::machine();
        $machine->enterService();

        $machine->restockItems(ItemInventory::fromQuantities(['WATER' => 5]));
        $machine->restockItems(ItemInventory::fromQuantities(['SODA' => 2]));

        self::assertSame(0, $machine->stockOf('WATER'), 'the previous stock is replaced, not topped up');
        self::assertSame(2, $machine->stockOf('SODA'));
    }

    public function test_setting_the_change_is_a_service_only_action(): void
    {
        $this->expectException(IllegalState::class);
        self::machine()->setAvailableChange(CoinSet::empty()->add(Coin::FIVE));
    }

    public function test_restocking_is_a_service_only_action(): void
    {
        $this->expectException(IllegalState::class);
        self::machine()->restockItems(ItemInventory::fromQuantities(['WATER' => 1]));
    }

    public function test_selecting_an_item_with_exact_payment_dispenses_it_with_no_change(): void
    {
        // Spec example 1: 1, 0.25, 0.25, GET-SODA -> SODA. 150c is paid exactly, so no change is
        // due and the sale succeeds even with an empty bank.
        $machine = self::machine();
        $machine->enterService();
        $machine->restockItems(ItemInventory::fromQuantities(['SODA' => 1]));
        $machine->leaveService();

        $machine->insertCoin(Coin::HUNDRED);
        $machine->insertCoin(Coin::TWENTY_FIVE);
        $machine->insertCoin(Coin::TWENTY_FIVE);

        $result = $machine->selectItem('SODA', new BacktrackingChangeStrategy());

        self::assertSame('SODA', $result->product->code);
        self::assertTrue($result->change->isEmpty(), 'exact payment yields no change');
        self::assertSame(0, $machine->stockOf('SODA'), 'the item was dispensed');
        self::assertTrue($machine->insertedAmount()->equals(Money::zero()), 'the session empties on a sale');
    }

    public function test_selecting_an_item_returns_the_exact_change_for_an_overpayment(): void
    {
        // Spec example 3: 1, GET-WATER -> WATER, 0.25, 0.10. WATER costs 0.65 and 1.00 is inserted,
        // so 0.35 is owed and composed as a 0.25 plus a 0.10 drawn from the bank.
        $machine = self::machine();
        $machine->enterService();
        $machine->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 2)->add(Coin::TEN, 2));
        $machine->restockItems(ItemInventory::fromQuantities(['WATER' => 1]));
        $machine->leaveService();

        $machine->insertCoin(Coin::HUNDRED);

        $result = $machine->selectItem('WATER', new BacktrackingChangeStrategy());

        self::assertSame('WATER', $result->product->code);
        self::assertTrue($result->change->total()->equals(Money::fromCents(35)), 'change owed is 0.35');
        self::assertSame(1, $result->change->count(Coin::TWENTY_FIVE), 'a 0.25 is returned');
        self::assertSame(1, $result->change->count(Coin::TEN), 'and a 0.10');
        self::assertSame(0, $machine->stockOf('WATER'), 'the item was dispensed');
        self::assertTrue($machine->insertedAmount()->equals(Money::zero()), 'the session empties on a sale');
    }

    public function test_a_sale_pours_the_inserted_coins_into_the_bank_so_money_is_conserved(): void
    {
        // The customer's coins join the bank only at commit (the late dump): the inserted 1.00 ends
        // up in the reserve, and the bank grows by exactly the price (1.00 in, 0.35 back out as
        // change, net +0.65). This is the per-sale conservation postcondition.
        $machine = self::machine();
        $machine->enterService();
        $machine->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 2)->add(Coin::TEN, 2)); // 0.70
        $machine->restockItems(ItemInventory::fromQuantities(['WATER' => 1]));
        $machine->leaveService();

        $machine->insertCoin(Coin::HUNDRED);
        $machine->selectItem('WATER', new BacktrackingChangeStrategy());

        self::assertSame(1, $machine->availableChange()->count(Coin::HUNDRED), 'the inserted 1.00 is now in the bank');
        self::assertTrue(
            $machine->availableChange()->total()->equals(Money::fromCents(135)),
            'the bank grew from 0.70 to 1.35 — exactly the 0.65 price',
        );
    }

    public function test_a_sale_that_cannot_make_change_fails_closed_and_leaves_the_machine_untouched(): void
    {
        // WATER costs 0.65; the customer pays 0.75 (0.25 x 3), so 0.10 is owed — but the bank holds
        // only 0.25 coins, so no exact change exists. The sale is rejected and, because nothing is
        // mutated until every check passes, the session, stock and bank are left exactly as they
        // were: fail-closed and non-destructive, so the customer can return the coins or retry.
        $machine = self::machine();
        $machine->enterService();
        $machine->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 5));
        $machine->restockItems(ItemInventory::fromQuantities(['WATER' => 1]));
        $machine->leaveService();

        $machine->insertCoin(Coin::TWENTY_FIVE);
        $machine->insertCoin(Coin::TWENTY_FIVE);
        $machine->insertCoin(Coin::TWENTY_FIVE);

        try {
            $machine->selectItem('WATER', new BacktrackingChangeStrategy());
            self::fail('Expected CannotDispenseChange');
        } catch (CannotDispenseChange) {
            self::assertTrue($machine->insertedAmount()->equals(Money::fromCents(75)), 'the session is intact');
            self::assertSame(1, $machine->stockOf('WATER'), 'the stock is untouched');
            self::assertSame(5, $machine->availableChange()->count(Coin::TWENTY_FIVE), 'the bank is untouched');
        }
    }

    public function test_selecting_an_item_without_enough_money_is_refused_and_keeps_the_session_for_a_retry(): void
    {
        // SODA costs 1.50 but only 0.35 is inserted. The sale is refused with InsufficientFunds and
        // the session is left intact, so the customer can insert more coins and retry.
        $machine = self::machine();
        $machine->enterService();
        $machine->restockItems(ItemInventory::fromQuantities(['SODA' => 1]));
        $machine->leaveService();

        $machine->insertCoin(Coin::TWENTY_FIVE);
        $machine->insertCoin(Coin::TEN);

        try {
            $machine->selectItem('SODA', new BacktrackingChangeStrategy());
            self::fail('Expected InsufficientFunds');
        } catch (InsufficientFunds) {
            self::assertTrue($machine->insertedAmount()->equals(Money::fromCents(35)), 'the session survives for a retry');
            self::assertSame(1, $machine->stockOf('SODA'), 'nothing was dispensed');
        }
    }

    public function test_selecting_an_out_of_stock_item_is_refused(): void
    {
        // SODA is a real product but was never restocked; selecting it raises OutOfStock even though
        // the inserted coins would cover the price.
        $machine = self::machine();
        $machine->insertCoin(Coin::HUNDRED);
        $machine->insertCoin(Coin::TWENTY_FIVE);
        $machine->insertCoin(Coin::TWENTY_FIVE);

        $this->expectException(OutOfStock::class);
        $machine->selectItem('SODA', new BacktrackingChangeStrategy());
    }

    public function test_selecting_a_product_absent_from_the_catalog_is_refused(): void
    {
        // COLA is not in the catalog at all; selecting it raises UnknownProduct.
        $machine = self::machine();
        $machine->insertCoin(Coin::HUNDRED);

        $this->expectException(UnknownProduct::class);
        $machine->selectItem('COLA', new BacktrackingChangeStrategy());
    }

    public function test_selecting_an_item_is_a_customer_only_action(): void
    {
        // Selling belongs to Operational mode; attempting it in Service is a driver bug (IllegalState).
        $machine = self::machine();
        $machine->enterService();

        $this->expectException(IllegalState::class);
        $machine->selectItem('SODA', new BacktrackingChangeStrategy());
    }

    private static function machine(): VendingMachine
    {
        return VendingMachine::operational(self::catalog());
    }

    /**
     * A fixture catalog with several products (N >= 3). Prices are multiples of 5 cents and match
     * the spec examples: SODA 1.50 (example 1) and WATER 0.65 (example 3).
     */
    private static function catalog(): Catalog
    {
        return Catalog::of(
            Product::create('WATER', Money::fromCents(65)),
            Product::create('SODA', Money::fromCents(150)),
            Product::create('JUICE', Money::fromCents(90)),
        );
    }
}
