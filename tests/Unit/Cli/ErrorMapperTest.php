<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use VendingMachine\Domain\Exception\CannotDispenseChange;
use VendingMachine\Domain\Exception\InsufficientFunds;
use VendingMachine\Domain\Exception\OutOfStock;
use VendingMachine\Domain\Exception\SessionNotEmpty;
use VendingMachine\Domain\Exception\UnknownProduct;
use VendingMachine\Infrastructure\Cli\ErrorMapper;
use VendingMachine\Infrastructure\Cli\InvalidCoin;
use VendingMachine\Infrastructure\Cli\InvalidCommand;

final class ErrorMapperTest extends TestCase
{
    #[DataProvider('recoverableErrors')]
    public function test_it_maps_each_recoverable_error_to_its_exit_code(Throwable $error, int $expected): void
    {
        self::assertSame($expected, (new ErrorMapper())->exitCodeFor($error));
    }

    /**
     * @return array<string, array{Throwable, int}>
     */
    public static function recoverableErrors(): array
    {
        return [
            'invalid coin is a usage error'        => [new InvalidCoin('x'), ErrorMapper::EXIT_INPUT_ERROR],
            'invalid command is a usage error'     => [new InvalidCommand('x'), ErrorMapper::EXIT_INPUT_ERROR],
            'unknown product is a domain refusal'  => [new UnknownProduct('x'), ErrorMapper::EXIT_DOMAIN_ERROR],
            'out of stock is a domain refusal'     => [new OutOfStock('x'), ErrorMapper::EXIT_DOMAIN_ERROR],
            'insufficient funds is a domain refusal' => [new InsufficientFunds('x'), ErrorMapper::EXIT_DOMAIN_ERROR],
            'no change is a domain refusal'        => [new CannotDispenseChange('x'), ErrorMapper::EXIT_DOMAIN_ERROR],
            'busy tray is a domain refusal'        => [new SessionNotEmpty('x'), ErrorMapper::EXIT_DOMAIN_ERROR],
        ];
    }
}
