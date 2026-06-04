<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Machine;

use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;

/**
 * The vending machine aggregate root — the single owner of a sale's transaction.
 *
 * It is an entity (it has identity and a lifecycle), so unlike the value objects it is built from it
 * is mutable. Inserted coins live in a retention tray (sessionCoins) kept separate from the bank: that
 * keeps RETURN-COIN a total operation and lets the customer's coins be poured into the bank only when a
 * sale commits. State is added to the aggregate as the behaviour that uses it lands (the item stock,
 * coin bank and catalog arrive with SERVICE and the sale).
 */
final class VendingMachine
{
    private function __construct(
        private OperationalMode $mode,
        private CoinSet $sessionCoins,
    ) {
    }

    public static function operational(): self
    {
        return new self(OperationalMode::Operational, CoinSet::empty());
    }

    public function mode(): OperationalMode
    {
        return $this->mode;
    }

    public function insertedAmount(): Money
    {
        return $this->sessionCoins->total();
    }

    public function insertCoin(Coin $coin): void
    {
        $this->sessionCoins = $this->sessionCoins->add($coin);
    }

    /**
     * Return exactly the coins inserted so far and empty the retention tray. Total operation: it
     * hands back the very coins it is holding, so it can never fail or shortchange the customer.
     */
    public function returnCoins(): CoinSet
    {
        $returned = $this->sessionCoins;
        $this->sessionCoins = CoinSet::empty();

        return $returned;
    }
}
