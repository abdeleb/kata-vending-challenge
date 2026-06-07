<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Machine\VendingResult;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;
use VendingMachine\Infrastructure\Cli\CoinParser;
use VendingMachine\Infrastructure\Cli\CommandInterpreter;
use VendingMachine\Infrastructure\Cli\InvalidCommand;
use VendingMachine\Infrastructure\Cli\OutputFormatter;
use VendingMachine\Tests\Support\RecordingMachineDriver;

final class CommandInterpreterTest extends TestCase
{
    public function test_it_routes_each_coin_token_to_insert_and_prints_nothing(): void
    {
        $driver = new RecordingMachineDriver();

        $output = $this->interpreterFor($driver)->interpret('1, 0.25, 0.25');

        self::assertSame([Coin::HUNDRED, Coin::TWENTY_FIVE, Coin::TWENTY_FIVE], $driver->insertedCoins);
        self::assertSame([], $output);
    }

    public function test_it_renders_a_sale_for_a_get_command(): void
    {
        $driver = new RecordingMachineDriver();
        $driver->saleResult = new VendingResult(
            Product::create('SODA', Money::fromCents(150)),
            CoinSet::empty(),
        );

        $output = $this->interpreterFor($driver)->interpret('GET-SODA');

        self::assertSame(['SODA'], $driver->selectedCodes);
        self::assertSame(['SODA'], $output);
    }

    public function test_it_renders_the_returned_coins_for_return_coin(): void
    {
        $driver = new RecordingMachineDriver();
        $driver->returnedCoins = CoinSet::empty()->add(Coin::TEN)->add(Coin::TEN);

        $output = $this->interpreterFor($driver)->interpret('0.10, 0.10, RETURN-COIN');

        self::assertSame(['0.10, 0.10'], $output);
    }

    public function test_it_threads_coins_then_a_sale_on_a_single_line(): void
    {
        $driver = new RecordingMachineDriver();
        $driver->saleResult = new VendingResult(
            Product::create('SODA', Money::fromCents(150)),
            CoinSet::empty(),
        );

        $output = $this->interpreterFor($driver)->interpret('1, 0.25, 0.25, GET-SODA');

        self::assertSame([Coin::HUNDRED, Coin::TWENTY_FIVE, Coin::TWENTY_FIVE], $driver->insertedCoins);
        self::assertSame(['SODA'], $driver->selectedCodes);
        self::assertSame(['SODA'], $output);
    }

    public function test_it_ignores_blank_tokens_and_an_empty_line(): void
    {
        $driver = new RecordingMachineDriver();

        self::assertSame([], $this->interpreterFor($driver)->interpret(''));
        self::assertSame([], $this->interpreterFor($driver)->interpret('   ,  , '));
        self::assertSame([], $driver->insertedCoins);
    }

    public function test_it_rejects_an_unrecognized_token(): void
    {
        $this->expectException(InvalidCommand::class);

        $this->interpreterFor(new RecordingMachineDriver())->interpret('FOO');
    }

    public function test_it_rejects_a_get_with_no_product_code(): void
    {
        $this->expectException(InvalidCommand::class);

        $this->interpreterFor(new RecordingMachineDriver())->interpret('GET-');
    }

    private function interpreterFor(RecordingMachineDriver $driver): CommandInterpreter
    {
        return new CommandInterpreter($driver, new CoinParser(), new OutputFormatter());
    }
}
