<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Catalog;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Exception\UnknownProduct;
use VendingMachine\Domain\Money\Money;

final class CatalogTest extends TestCase
{
    public function test_looks_up_a_product_by_code(): void
    {
        $soda = $this->sampleCatalog()->productFor('SODA');

        self::assertSame('SODA', $soda->code);
        self::assertSame(150, $soda->price->cents);
    }

    public function test_knows_whether_a_code_exists(): void
    {
        $catalog = $this->sampleCatalog();

        self::assertTrue($catalog->has('WATER'));
        self::assertFalse($catalog->has('BEER'));
    }

    public function test_selecting_an_unknown_code_throws_a_domain_exception(): void
    {
        $this->expectException(UnknownProduct::class);

        $this->sampleCatalog()->productFor('BEER');
    }

    public function test_supports_n_products_not_hardcoded_to_three(): void
    {
        $catalog = Catalog::of(
            Product::create('WATER', Money::fromCents(65)),
            Product::create('SODA', Money::fromCents(150)),
        );

        self::assertCount(2, $catalog->all());
    }

    public function test_a_catalog_cannot_be_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Catalog::of();
    }

    public function test_rejects_duplicate_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Catalog::of(
            Product::create('WATER', Money::fromCents(65)),
            Product::create('WATER', Money::fromCents(100)),
        );
    }

    private function sampleCatalog(): Catalog
    {
        return Catalog::of(
            Product::create('WATER', Money::fromCents(65)),
            Product::create('JUICE', Money::fromCents(100)),
            Product::create('SODA', Money::fromCents(150)),
        );
    }
}
