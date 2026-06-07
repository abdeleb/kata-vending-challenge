<?php

declare(strict_types=1);

namespace VendingMachine\Application\Service;

use VendingMachine\Application\Port\MachineDriver;
use VendingMachine\Domain\Change\ChangeStrategy;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\VendingResult;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Application service implementing the driving port: it orchestrates each use case as
 * load -> delegate to the aggregate -> save, and holds no business logic of its own. Every rule —
 * mode guards, stock, funds, change, transactionality — lives in the aggregate, so deleting this
 * class would not weaken a single invariant; it only wires persistence around the domain.
 *
 * The change strategy is injected here and handed to the aggregate inside selectItem, so it stays
 * out of the driving port's contract and out of the aggregate's persistable state. A failing
 * operation throws from the aggregate before save() is reached, so a refused command persists
 * nothing; the exception bubbles to the delivery adapter, which maps it to a user-facing message.
 */
final class VendingMachineService implements MachineDriver
{
    public function __construct(
        private readonly VendingMachineRepository $repository,
        private readonly ChangeStrategy $strategy,
    ) {
    }

    public function insertCoin(Coin $coin): void
    {
        $machine = $this->repository->load();
        $machine->insertCoin($coin);
        $this->repository->save($machine);
    }

    public function returnCoins(): CoinSet
    {
        $machine = $this->repository->load();
        $returned = $machine->returnCoins();
        $this->repository->save($machine);

        return $returned;
    }

    public function selectItem(string $code): VendingResult
    {
        $machine = $this->repository->load();
        $result = $machine->selectItem($code, $this->strategy);
        $this->repository->save($machine);

        return $result;
    }

    public function enterService(): void
    {
        $machine = $this->repository->load();
        $machine->enterService();
        $this->repository->save($machine);
    }

    public function leaveService(): void
    {
        $machine = $this->repository->load();
        $machine->leaveService();
        $this->repository->save($machine);
    }

    public function setAvailableChange(CoinSet $coins): void
    {
        $machine = $this->repository->load();
        $machine->setAvailableChange($coins);
        $this->repository->save($machine);
    }

    public function restockItems(ItemInventory $items): void
    {
        $machine = $this->repository->load();
        $machine->restockItems($items);
        $this->repository->save($machine);
    }
}
