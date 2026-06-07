<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Infrastructure\Cli\CoinParser;
use VendingMachine\Infrastructure\Cli\InvalidCoin;

final class CoinParserTest extends TestCase
{
    #[DataProvider('acceptedCoins')]
    public function test_it_parses_a_decimal_token_into_the_exact_coin(string $token, Coin $expected): void
    {
        self::assertSame($expected, (new CoinParser())->parse($token));
    }

    #[DataProvider('rejectedTokens')]
    public function test_it_rejects_anything_that_is_not_an_accepted_coin(string $token): void
    {
        $this->expectException(InvalidCoin::class);
        (new CoinParser())->parse($token);
    }

    /**
     * @return array<string, array{string, Coin}>
     */
    public static function acceptedCoins(): array
    {
        return [
            'one euro'              => ['1', Coin::HUNDRED],
            'one euro, two places'  => ['1.00', Coin::HUNDRED],
            'a quarter'             => ['0.25', Coin::TWENTY_FIVE],
            'ten cents'             => ['0.10', Coin::TEN],
            'ten cents, one place'  => ['0.1', Coin::TEN],
            'five cents'            => ['0.05', Coin::FIVE],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedTokens(): array
    {
        return [
            'not a denomination'    => ['0.30'],
            'half euro'             => ['0.50'],
            'fifty cents one place' => ['0.5'],
            'one fifty'             => ['1.50'],
            'one and a half'        => ['1.5'],
            'two euros'             => ['2'],
            'zero'                  => ['0'],
            'no leading digit'      => ['.25'],
            'three decimal places'  => ['0.250'],
            'comma separator'       => ['1,25'],
            'negative'              => ['-5'],
            'non numeric'           => ['abc'],
            'empty'                 => [''],
            'surrounding spaces'    => [' 0.25 '],
        ];
    }
}
