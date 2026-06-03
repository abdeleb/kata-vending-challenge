<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Catalog;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Money\Money;

final class ProductTest extends TestCase
{
    public function test_exposes_code_and_price(): void
    {
        $water = Product::create('WATER', Money::fromCents(65));

        self::assertSame('WATER', $water->code);
        self::assertSame(65, $water->price->cents);
    }

    public function test_price_must_be_a_multiple_of_5_cents(): void
    {
        // 63c is not reachable with {5,10,25,100}; rejecting it at construction makes a
        // non-representable change amount structurally impossible later.
        $this->expectException(InvalidArgumentException::class);

        Product::create('WEIRD', Money::fromCents(63));
    }

    public function test_price_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Product::create('FREE', Money::fromCents(0));
    }

    public function test_code_cannot_be_blank(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Product::create('   ', Money::fromCents(100));
    }

    public function test_equality_is_by_value(): void
    {
        $a = Product::create('SODA', Money::fromCents(150));
        $b = Product::create('SODA', Money::fromCents(150));
        $c = Product::create('SODA', Money::fromCents(100));

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
