<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\VendingMachine;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;
use VendingMachine\Domain\Repository\VendingMachineRepository;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

final class InMemoryVendingMachineRepositoryTest extends TestCase
{
    public function test_it_loads_the_machine_it_was_seeded_with(): void
    {
        // There is a single physical cabinet, so the machine always exists: load() is total. The
        // in-memory adapter is seeded at construction because, unlike a database, it has no external
        // store to read the initial state from. So a load() before any save() returns that seed.
        $repository = self::repository(self::machine());

        $loaded = $repository->load();

        self::assertTrue($loaded->availableChange()->isEmpty());
        self::assertSame(0, $loaded->stockOf('WATER'));
    }

    public function test_it_round_trips_the_full_machine_state_through_save_and_load(): void
    {
        // Drive the machine into a non-trivial state, persist it over the seed, and read it back: the
        // reloaded machine must report the same mode, session balance, change reserve and stock,
        // down to the individual coin denomination.
        $machine = self::machine();
        $machine->enterService();
        $machine->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 3)->add(Coin::TEN, 1));
        $machine->restockItems(ItemInventory::fromQuantities(['WATER' => 4, 'SODA' => 2]));
        $machine->leaveService();
        $machine->insertCoin(Coin::HUNDRED);

        $repository = self::repository(self::machine());
        $repository->save($machine);
        $loaded = $repository->load();

        self::assertSame($machine->mode(), $loaded->mode());
        self::assertTrue($loaded->insertedAmount()->equals($machine->insertedAmount()));
        self::assertSame(4, $loaded->stockOf('WATER'));
        self::assertSame(2, $loaded->stockOf('SODA'));

        foreach (Coin::cases() as $coin) {
            self::assertSame(
                $machine->availableChange()->count($coin),
                $loaded->availableChange()->count($coin),
                "the change reserve differs for the {$coin->valueInCents()}c coin",
            );
        }
    }

    public function test_load_returns_an_isolated_snapshot_so_later_mutations_do_not_leak_back(): void
    {
        // The aggregate is mutable, so a repository handing out the stored reference would let a
        // caller change persisted state without saving — a leak a real database never has. load()
        // must return an independent snapshot.
        $repository = self::repository(self::machine());

        $first = $repository->load();
        $first->insertCoin(Coin::TWENTY_FIVE);

        self::assertTrue(
            $repository->load()->insertedAmount()->equals(Money::zero()),
            'the unsaved mutation on a loaded copy must not be visible to the next load',
        );
    }

    public function test_save_snapshots_the_machine_so_later_mutations_need_an_explicit_resave(): void
    {
        // The symmetric guarantee: save() captures the state at the moment of the call. Mutating the
        // same instance afterwards persists nothing until it is saved again — save is the only
        // persistence point.
        $machine = self::machine();
        $repository = self::repository(self::machine());

        $repository->save($machine);
        $machine->insertCoin(Coin::TWENTY_FIVE);

        self::assertTrue(
            $repository->load()->insertedAmount()->equals(Money::zero()),
            'a mutation made after save() must not be persisted without a resave',
        );
    }

    private static function repository(VendingMachine $machine): VendingMachineRepository
    {
        return new InMemoryVendingMachineRepository($machine);
    }

    private static function machine(): VendingMachine
    {
        return VendingMachine::operational(self::catalog());
    }

    private static function catalog(): Catalog
    {
        return Catalog::of(
            Product::create('WATER', Money::fromCents(65)),
            Product::create('SODA', Money::fromCents(150)),
            Product::create('JUICE', Money::fromCents(90)),
        );
    }
}
