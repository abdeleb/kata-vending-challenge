<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Inventory;

use InvalidArgumentException;
use VendingMachine\Domain\Exception\OutOfStock;

/**
 * Immutable per-product stock, keyed by product code.
 *
 * Decoupled from the Catalog: it only knows codes and counts. Whether a code is a real product
 * is the Catalog's concern; the aggregate composes the two. Zero entries are normalized away.
 */
final readonly class ItemInventory
{
    /**
     * @param array<string, int> $quantities code => strictly positive quantity
     */
    private function __construct(private array $quantities)
    {
    }

    /**
     * @param array<string, int> $quantities code => non-negative quantity
     */
    public static function fromQuantities(array $quantities): self
    {
        foreach ($quantities as $code => $quantity) {
            if ($quantity < 0) {
                throw new InvalidArgumentException("Stock cannot be negative for '{$code}', got {$quantity}.");
            }
        }

        return new self(array_filter($quantities, static fn (int $quantity): bool => $quantity > 0));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function stockFor(string $code): int
    {
        return $this->quantities[$code] ?? 0;
    }

    /**
     * The product codes this inventory carries stock for. Lets the aggregate cross-check a declared
     * stock against the catalog without this type having to know what a catalog is.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->quantities);
    }

    public function hasStock(string $code): bool
    {
        return $this->stockFor($code) > 0;
    }

    public function dispense(string $code): self
    {
        $current = $this->stockFor($code);

        if ($current === 0) {
            throw new OutOfStock("No stock to dispense for '{$code}'.");
        }

        $quantities = $this->quantities;
        $remaining = $current - 1;

        if ($remaining === 0) {
            unset($quantities[$code]);
        } else {
            $quantities[$code] = $remaining;
        }

        return new self($quantities);
    }
}
