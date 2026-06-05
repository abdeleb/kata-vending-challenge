<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Machine;

use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Money\CoinSet;

/**
 * The outcome of a successful sale: the product dispensed and the change returned (an empty CoinSet
 * when payment was exact). A small immutable value object so selectItem can hand back both halves of
 * the transaction at once.
 *
 * Unlike the other value objects it has no invariant to enforce — any product paired with any change
 * is a valid result — so its constructor is public: a private constructor plus a named factory would
 * be ceremony that protects nothing.
 */
final readonly class VendingResult
{
    public function __construct(
        public Product $product,
        public CoinSet $change,
    ) {
    }
}
