<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Inventory;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Exception\OutOfStock;
use VendingMachine\Domain\Inventory\ItemInventory;

final class ItemInventoryTest extends TestCase
{
    public function test_reports_stock_per_code(): void
    {
        $inventory = ItemInventory::fromQuantities(['WATER' => 3, 'SODA' => 1]);

        self::assertSame(3, $inventory->stockFor('WATER'));
        self::assertSame(1, $inventory->stockFor('SODA'));
        self::assertSame(0, $inventory->stockFor('JUICE'));
    }

    public function test_has_stock_reflects_availability(): void
    {
        $inventory = ItemInventory::fromQuantities(['WATER' => 1]);

        self::assertTrue($inventory->hasStock('WATER'));
        self::assertFalse($inventory->hasStock('SODA'));
    }

    public function test_dispensing_decrements_and_is_immutable(): void
    {
        $three = ItemInventory::fromQuantities(['WATER' => 3]);

        $two = $three->dispense('WATER');

        self::assertSame(2, $two->stockFor('WATER'));
        self::assertSame(3, $three->stockFor('WATER'), 'original inventory must be untouched');
    }

    public function test_dispensing_the_last_unit_leaves_no_stock(): void
    {
        $inventory = ItemInventory::fromQuantities(['WATER' => 1])->dispense('WATER');

        self::assertFalse($inventory->hasStock('WATER'));
    }

    public function test_dispensing_with_no_stock_fails_closed(): void
    {
        $this->expectException(OutOfStock::class);

        ItemInventory::fromQuantities(['WATER' => 0])->dispense('WATER');
    }

    public function test_construction_rejects_negative_stock(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ItemInventory::fromQuantities(['WATER' => -1]);
    }
}
