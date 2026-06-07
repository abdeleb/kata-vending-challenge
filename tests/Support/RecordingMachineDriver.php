<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Support;

use LogicException;
use VendingMachine\Application\Port\MachineDriver;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\VendingResult;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;

/**
 * A test double for the driving port that records the calls the CLI adapter makes and hands back
 * canned results, so the command interpreter can be tested in isolation from the domain and
 * persistence. The real end-to-end wiring is exercised by the acceptance suite instead.
 */
final class RecordingMachineDriver implements MachineDriver
{
    /** @var list<Coin> */
    public array $insertedCoins = [];

    /** @var list<string> */
    public array $selectedCodes = [];

    /** @var list<string> */
    public array $serviceCalls = [];

    public ?VendingResult $saleResult = null;

    public CoinSet $returnedCoins;

    public ?CoinSet $changeSet = null;

    public ?ItemInventory $restocked = null;

    public function __construct()
    {
        $this->returnedCoins = CoinSet::empty();
    }

    public function insertCoin(Coin $coin): void
    {
        $this->insertedCoins[] = $coin;
    }

    public function returnCoins(): CoinSet
    {
        $this->serviceCalls[] = 'returnCoins';

        return $this->returnedCoins;
    }

    public function selectItem(string $code): VendingResult
    {
        $this->selectedCodes[] = $code;

        return $this->saleResult ?? throw new LogicException('No canned sale result was set on the spy.');
    }

    public function enterService(): void
    {
        $this->serviceCalls[] = 'enterService';
    }

    public function leaveService(): void
    {
        $this->serviceCalls[] = 'leaveService';
    }

    public function setAvailableChange(CoinSet $coins): void
    {
        $this->serviceCalls[] = 'setAvailableChange';
        $this->changeSet = $coins;
    }

    public function restockItems(ItemInventory $items): void
    {
        $this->serviceCalls[] = 'restockItems';
        $this->restocked = $items;
    }
}
