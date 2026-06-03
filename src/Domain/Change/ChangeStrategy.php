<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Change;

use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;

/**
 * Computes the change to dispense for a sale — a pure, internal domain abstraction.
 *
 * This is a Strategy, not a hexagonal port: the computation is deterministic and does no
 * I/O, so it never crosses an infrastructure boundary. It is injected only to vary the
 * algorithm and to exercise both implementations against one shared contract test.
 */
interface ChangeStrategy
{
    /**
     * @param Money   $due       the change owed (zero when payment was exact)
     * @param CoinSet $available the coins on hand to draw change from
     *
     * @return CoinSet|null the coins to dispense — an empty CoinSet when no change is due —
     *                      or null when no combination of available, dispensable coins sums
     *                      to $due exactly, so the caller can fail the sale closed
     */
    public function computeChange(Money $due, CoinSet $available): ?CoinSet;
}
