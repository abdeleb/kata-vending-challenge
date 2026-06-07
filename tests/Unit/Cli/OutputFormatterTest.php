<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Infrastructure\Cli\OutputFormatter;

final class OutputFormatterTest extends TestCase
{
    #[DataProvider('coins')]
    public function test_it_formats_a_coin_as_a_canonical_decimal_string(Coin $coin, string $expected): void
    {
        self::assertSame($expected, (new OutputFormatter())->formatCoin($coin));
    }

    /**
     * @return array<string, array{Coin, string}>
     */
    public static function coins(): array
    {
        return [
            'five cents' => [Coin::FIVE, '0.05'],
            'ten cents'  => [Coin::TEN, '0.10'],
            'a quarter'  => [Coin::TWENTY_FIVE, '0.25'],
            'one euro'   => [Coin::HUNDRED, '1.00'],
        ];
    }
}
