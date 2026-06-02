<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Money;

use InvalidArgumentException;

/**
 * A non-negative monetary amount stored as integer cents. Immutable.
 *
 * Arithmetic is exact (no floats). A negative amount is an invariant violation —
 * a programming error — so it raises InvalidArgumentException, never a domain-flow
 * exception such as InsufficientFunds (those model business outcomes, not broken invariants).
 */
final readonly class Money
{
    private function __construct(public int $cents)
    {
    }

    public static function fromCents(int $cents): self
    {
        if ($cents < 0) {
            throw new InvalidArgumentException("Money cannot be negative, got {$cents} cents.");
        }

        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        if ($other->cents > $this->cents) {
            throw new InvalidArgumentException('Money cannot be negative after subtraction.');
        }

        return new self($this->cents - $other->cents);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->cents >= $other->cents;
    }
}
