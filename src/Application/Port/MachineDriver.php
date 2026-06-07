<?php

declare(strict_types=1);

namespace VendingMachine\Application\Port;

use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\VendingResult;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;

/**
 * Driving (input) port: the application's use-case API.
 *
 * A delivery adapter — the CLI today, an HTTP controller tomorrow — depends on this interface, never
 * on the concrete service, so the way the machine is driven can change without touching the
 * application or the domain. The methods mirror the use cases, not an algorithm: selecting an item
 * takes only the code, because which change strategy is used is application wiring, not part of the
 * contract a caller sees.
 */
interface MachineDriver
{
    public function insertCoin(Coin $coin): void;

    public function returnCoins(): CoinSet;

    public function selectItem(string $code): VendingResult;

    public function enterService(): void;

    public function leaveService(): void;

    public function setAvailableChange(CoinSet $coins): void;

    public function restockItems(ItemInventory $items): void;
}
