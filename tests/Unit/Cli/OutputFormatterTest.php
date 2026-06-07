<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Infrastructure\Cli\OutputFormatter;

final class OutputFormatterTest extends TestCase
{
    #[DataProvider('coins')]
    public function test_it_formats_a_coin_as_a_canonical_decimal_string(Coin $coin, string $expected): void
    {
        self::assertSame($expected, (new OutputFormatter())->formatCoin($coin));
    }

    #[DataProvider('coinSets')]
    public function test_it_formats_a_coin_set_highest_first_joined_by_commas(CoinSet $coins, string $expected): void
    {
        self::assertSame($expected, (new OutputFormatter())->formatCoins($coins));
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

    /**
     * @return array<string, array{CoinSet, string}>
     */
    public static function coinSets(): array
    {
        return [
            'no coins'            => [CoinSet::empty(), ''],
            'a single quarter'    => [CoinSet::empty()->add(Coin::TWENTY_FIVE), '0.25'],
            'repeated coins'      => [CoinSet::empty()->add(Coin::TEN, 2), '0.10, 0.10'],
            'sale change'         => [CoinSet::empty()->add(Coin::TWENTY_FIVE)->add(Coin::TEN), '0.25, 0.10'],
            'ordered highest first' => [
                CoinSet::empty()->add(Coin::FIVE)->add(Coin::TWENTY_FIVE)->add(Coin::TEN),
                '0.25, 0.10, 0.05',
            ],
            'a returned euro'     => [CoinSet::empty()->add(Coin::HUNDRED)->add(Coin::TWENTY_FIVE), '1.00, 0.25'],
        ];
    }
}
