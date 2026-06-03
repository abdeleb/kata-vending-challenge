<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Catalog;

use InvalidArgumentException;
use VendingMachine\Domain\Exception\UnknownProduct;

/**
 * An immutable collection of the products a machine sells, indexed by code.
 *
 * Supports N products (the spec asks for "at least 3"); the concrete items are data, not
 * structure. Construction is fail-fast: a catalog cannot be empty and codes must be unique.
 */
final readonly class Catalog
{
    /**
     * @param array<string, Product> $productsByCode
     */
    private function __construct(private array $productsByCode)
    {
    }

    public static function of(Product ...$products): self
    {
        if ($products === []) {
            throw new InvalidArgumentException('A catalog must contain at least one product.');
        }

        $byCode = [];
        foreach ($products as $product) {
            if (isset($byCode[$product->code])) {
                throw new InvalidArgumentException("Duplicate product code: {$product->code}.");
            }

            $byCode[$product->code] = $product;
        }

        return new self($byCode);
    }

    public function has(string $code): bool
    {
        return isset($this->productsByCode[$code]);
    }

    public function productFor(string $code): Product
    {
        return $this->productsByCode[$code]
            ?? throw new UnknownProduct("No product with code '{$code}'.");
    }

    /**
     * @return list<Product>
     */
    public function all(): array
    {
        return array_values($this->productsByCode);
    }
}
