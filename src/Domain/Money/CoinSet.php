<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Money;

use InvalidArgumentException;

/**
 * An immutable multiset of coins: how many of each denomination are present.
 *
 * Backs both the session retention tray (coins the customer has inserted) and the
 * coin inventories (bank / change drawer). total() bridges a CoinSet to a Money.
 * Zero counts are never stored, so isEmpty() is a reliable emptiness check.
 */
final readonly class CoinSet
{
    /**
     * @param array<int, int> $countsByCents denomination in cents => strictly positive count
     */
    private function __construct(private array $countsByCents)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function add(Coin $coin, int $count = 1): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException("Cannot add a negative number of coins ({$count}).");
        }

        $cents = $coin->valueInCents();
        $counts = $this->countsByCents;
        $new = ($counts[$cents] ?? 0) + $count;

        if ($new === 0) {
            unset($counts[$cents]);
        } else {
            $counts[$cents] = $new;
        }

        return new self($counts);
    }

    public function remove(Coin $coin, int $count = 1): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException("Cannot remove a negative number of coins ({$count}).");
        }

        $available = $this->count($coin);

        if ($count > $available) {
            throw new InvalidArgumentException(
                "Cannot remove {$count} coin(s) of {$coin->valueInCents()}c; only {$available} available.",
            );
        }

        $cents = $coin->valueInCents();
        $counts = $this->countsByCents;
        $remaining = $available - $count;

        if ($remaining === 0) {
            unset($counts[$cents]);
        } else {
            $counts[$cents] = $remaining;
        }

        return new self($counts);
    }

    public function count(Coin $coin): int
    {
        return $this->countsByCents[$coin->valueInCents()] ?? 0;
    }

    public function total(): Money
    {
        $sum = 0;

        foreach ($this->countsByCents as $cents => $count) {
            $sum += $cents * $count;
        }

        return Money::fromCents($sum);
    }

    public function isEmpty(): bool
    {
        return $this->countsByCents === [];
    }
}
