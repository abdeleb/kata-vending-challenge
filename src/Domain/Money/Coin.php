<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Money;

/**
 * A physical coin the machine handles, backed by its value in cents.
 *
 * Accepted input denominations: 5, 10, 25, 100. Change denominations: 5, 10, 25 — the 100c
 * coin pays into the bank but is never dispensed as change ("0.05, 0.10, 0.25 - return coin").
 */
enum Coin: int
{
    case FIVE = 5;
    case TEN = 10;
    case TWENTY_FIVE = 25;
    case HUNDRED = 100;

    public function valueInCents(): int
    {
        return $this->value;
    }

    public function isDispensableAsChange(): bool
    {
        return match ($this) {
            self::FIVE, self::TEN, self::TWENTY_FIVE => true,
            self::HUNDRED => false,
        };
    }
}
