<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Machine;

use function sprintf;

use VendingMachine\Domain\Exception\IllegalState;
use VendingMachine\Domain\Exception\SessionNotEmpty;
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
