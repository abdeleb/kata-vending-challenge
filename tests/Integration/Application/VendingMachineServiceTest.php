<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use VendingMachine\Application\Port\MachineDriver;
use VendingMachine\Application\Service\VendingMachineService;
use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Change\BacktrackingChangeStrategy;
use VendingMachine\Domain\Exception\CannotDispenseChange;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\OperationalMode;
use VendingMachine\Domain\Machine\VendingMachine;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;
use VendingMachine\Domain\Repository\VendingMachineRepository;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

final class VendingMachineServiceTest extends TestCase
{
    public function test_a_full_sale_threads_state_through_the_repository(): void
    {
        // Each service call is its own load -> delegate -> save, so the flow only completes if state
        // persists between calls: setAvailableChange right after enterService would raise IllegalState
        // if the second call loaded a fresh Operational machine instead of the saved Service one. The
        // sale itself then sees the stock and bank that earlier calls committed.
        $repository = self::repository();
        $service = self::driver($repository);

        $service->enterService();
        $service->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 2)->add(Coin::TEN, 2)); // 0.70
        $service->restockItems(ItemInventory::fromQuantities(['WATER' => 1]));
        $service->leaveService();

        $service->insertCoin(Coin::HUNDRED);
        $result = $service->selectItem('WATER'); // 0.65 paid with 1.00 -> 0.35 change (0.25 + 0.10)

        self::assertSame('WATER', $result->product->code);
        self::assertTrue($result->change->total()->equals(Money::fromCents(35)));

        $persisted = $repository->load();
        self::assertSame(OperationalMode::Operational, $persisted->mode(), 'leaveService persisted');
        self::assertSame(0, $persisted->stockOf('WATER'), 'the sale persisted');
        self::assertTrue($persisted->insertedAmount()->equals(Money::zero()), 'the emptied session persisted');
        self::assertSame(1, $persisted->availableChange()->count(Coin::HUNDRED), 'the inserted 1.00 reached the bank and persisted');
    }

    public function test_return_coin_hands_back_the_inserted_coins_and_persists_the_emptied_session(): void
    {
        $repository = self::repository();
        $service = self::driver($repository);

        $service->insertCoin(Coin::TEN);
        $service->insertCoin(Coin::TEN);

        $returned = $service->returnCoins();

        self::assertSame(2, $returned->count(Coin::TEN));
        self::assertTrue($repository->load()->insertedAmount()->equals(Money::zero()), 'the emptied session persisted');
    }

    public function test_a_refused_sale_propagates_and_persists_nothing(): void
    {
        // WATER costs 0.65; the customer pays 0.75 (0.25 x 3) so 0.10 is owed, but the bank holds only
        // 0.25 coins, so no change exists. selectItem fails closed in the aggregate; the exception
        // bubbles through the service, so save() is never reached and nothing is persisted — the
        // session, stock and bank are left exactly as the prior committed calls had them.
        $repository = self::repository();
        $service = self::driver($repository);

        $service->enterService();
        $service->setAvailableChange(CoinSet::empty()->add(Coin::TWENTY_FIVE, 5));
        $service->restockItems(ItemInventory::fromQuantities(['WATER' => 1]));
        $service->leaveService();

        $service->insertCoin(Coin::TWENTY_FIVE);
        $service->insertCoin(Coin::TWENTY_FIVE);
        $service->insertCoin(Coin::TWENTY_FIVE);

        try {
            $service->selectItem('WATER');
            self::fail('Expected CannotDispenseChange');
        } catch (CannotDispenseChange) {
            $persisted = $repository->load();
            self::assertTrue($persisted->insertedAmount()->equals(Money::fromCents(75)), 'the session survives for a retry or return');
            self::assertSame(1, $persisted->stockOf('WATER'), 'the stock is untouched');
            self::assertSame(5, $persisted->availableChange()->count(Coin::TWENTY_FIVE), 'the bank is untouched');
        }
    }

    private static function repository(): VendingMachineRepository
    {
        return new InMemoryVendingMachineRepository(VendingMachine::operational(self::catalog()));
    }

    private static function driver(VendingMachineRepository $repository): MachineDriver
    {
        return new VendingMachineService($repository, new BacktrackingChangeStrategy());
    }

    private static function catalog(): Catalog
    {
        return Catalog::of(
            Product::create('WATER', Money::fromCents(65)),
            Product::create('SODA', Money::fromCents(150)),
            Product::create('JUICE', Money::fromCents(100)),
        );
    }
}
