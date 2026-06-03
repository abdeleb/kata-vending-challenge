<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Catalog;

use InvalidArgumentException;
use VendingMachine\Domain\Money\Money;

/**
 * A catalog item: a selection code (SKU) and a price. Immutable value object.
 *
 * The price must be a positive multiple of 5 cents. Since every accepted coin is a multiple
 * of 5, an inserted total is always a multiple of 5; requiring the price to be one too makes
 * the change due always a multiple of 5 — i.e. always representable with {5,10,25}. Feasibility
 * is then bounded only by coin stock, never by arithmetic. Validated fail-fast at construction.
 */
final readonly class Product
{
    private function __construct(
        public string $code,
        public Money $price,
    ) {
    }

    public static function create(string $code, Money $price): self
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Product code cannot be blank.');
        }

        if ($price->cents <= 0) {
            throw new InvalidArgumentException("Product price must be positive, got {$price->cents}c.");
        }

        if ($price->cents % 5 !== 0) {
            throw new InvalidArgumentException(
                "Product price must be a multiple of 5 cents so change stays representable, got {$price->cents}c.",
            );
        }

        return new self($code, $price);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code && $this->price->equals($other->price);
    }
}
