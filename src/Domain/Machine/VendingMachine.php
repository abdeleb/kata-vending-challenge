<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Machine;

use function sprintf;

use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Change\ChangeStrategy;
use VendingMachine\Domain\Exception\CannotDispenseChange;
use VendingMachine\Domain\Exception\IllegalState;
use VendingMachine\Domain\Exception\InsufficientFunds;
use VendingMachine\Domain\Exception\OutOfStock;
use VendingMachine\Domain\Exception\SessionNotEmpty;
use VendingMachine\Domain\Inventory\ItemInventory;
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
        private CoinSet $bank,
        private ItemInventory $items,
        private readonly Catalog $catalog,
    ) {
    }

    public static function operational(Catalog $catalog): self
    {
        return new self(
            OperationalMode::Operational,
            CoinSet::empty(),
            CoinSet::empty(),
            ItemInventory::empty(),
            $catalog,
        );
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
        $this->guardMode(OperationalMode::Operational);

        $this->sessionCoins = $this->sessionCoins->add($coin);
    }

    /**
     * Return exactly the coins inserted so far and empty the retention tray. Total operation: it
     * hands back the very coins it is holding, so it can never fail or shortchange the customer.
     */
    public function returnCoins(): CoinSet
    {
        $this->guardMode(OperationalMode::Operational);

        $returned = $this->sessionCoins;
        $this->sessionCoins = CoinSet::empty();

        return $returned;
    }

    /**
     * Sell a product. The aggregate owns the whole transaction and decides, by itself, whether the
     * sale is legal: it checks the mode, that the product exists, that it is in stock and that the
     * inserted coins cover the price, then asks the strategy whether exact change can be composed
     * from the tentative bank (the reserve plus the customer's own coins). Only once every check has
     * passed does it mutate — the new bank, stock and emptied session are computed into locals and
     * assigned last, so a sale that cannot complete leaves the machine untouched (all-or-nothing).
     *
     * The change strategy is injected here rather than held as state: it is stateless behaviour used
     * only by the sale, so it stays out of the aggregate's persistable data, unlike the catalog.
     */
    public function selectItem(string $code, ChangeStrategy $strategy): VendingResult
    {
        $this->guardMode(OperationalMode::Operational);

        $product = $this->catalog->productFor($code);

        if (!$this->items->hasStock($code)) {
            throw new OutOfStock("No stock to dispense for '{$code}'.");
        }

        $inserted = $this->sessionCoins->total();

        if (!$inserted->isGreaterThanOrEqualTo($product->price)) {
            throw new InsufficientFunds(
                sprintf("Inserted %dc, but '%s' costs %dc.", $inserted->cents, $code, $product->price->cents),
            );
        }

        $due = $inserted->subtract($product->price);
        $tentativeBank = $this->bank->plus($this->sessionCoins);
        $change = $strategy->computeChange($due, $tentativeBank);

        if ($change === null) {
            throw new CannotDispenseChange(
                sprintf("Cannot compose %dc of change for '%s' from the available coins.", $due->cents, $code),
            );
        }

        $newBank = $tentativeBank->minus($change);
        $newItems = $this->items->dispense($code);

        $this->bank = $newBank;
        $this->items = $newItems;
        $this->sessionCoins = CoinSet::empty();

        return new VendingResult($product, $change);
    }

    /**
     * Open the machine for servicing (Operational -> Service).
     *
     * The customer side must be settled first: entering Service with coins still in the tray raises
     * SessionNotEmpty (a recoverable condition — the technician returns the coins and retries), not
     * an IllegalState. Returning those coins is left to RETURN-COIN so this transition stays
     * single-purpose.
     */
    public function enterService(): void
    {
        $this->guardMode(OperationalMode::Operational);

        if (!$this->sessionCoins->isEmpty()) {
            throw new SessionNotEmpty('Cannot enter Service while coins remain in the tray; return them first.');
        }

        $this->mode = OperationalMode::Service;
    }

    /**
     * Close the machine and return it to normal operation (Service -> Operational).
     */
    public function leaveService(): void
    {
        $this->guardMode(OperationalMode::Service);

        $this->mode = OperationalMode::Operational;
    }

    /**
     * Service operation: declare the machine's change reserve.
     *
     * Set semantics, not add — the spec says "set the available change", so the technician states
     * the absolute coin inventory left in the drawer, replacing whatever was there. This is the one
     * point where money conservation deliberately does not hold: coins enter (or leave) the system by
     * the technician's decision, not by a sale. An incremental "add change" would be a separate,
     * differently-named operation.
     */
    public function setAvailableChange(CoinSet $coins): void
    {
        $this->guardMode(OperationalMode::Service);

        $this->bank = $coins;
    }

    /**
     * Service operation: declare the product stock. Set semantics, like setAvailableChange — the
     * technician states what is in the machine after refilling, replacing the previous stock.
     */
    public function restockItems(ItemInventory $items): void
    {
        $this->guardMode(OperationalMode::Service);

        $this->items = $items;
    }

    /**
     * The coins currently held as change reserve (the bank), distinct from the customer's session.
     */
    public function availableChange(): CoinSet
    {
        return $this->bank;
    }

    public function stockOf(string $code): int
    {
        return $this->items->stockFor($code);
    }

    /**
     * Enforce that an operation runs only in the mode it belongs to.
     *
     * Customer actions require Operational and service actions require Service; a call from the
     * wrong mode is something a correct driver never does, so it is a programming bug (IllegalState,
     * a LogicException that bubbles unmapped) rather than a user-facing domain error.
     */
    private function guardMode(OperationalMode $required): void
    {
        if ($this->mode !== $required) {
            throw new IllegalState(
                sprintf('Operation requires %s mode, but the machine is in %s mode.', $required->name, $this->mode->name),
            );
        }
    }
}
