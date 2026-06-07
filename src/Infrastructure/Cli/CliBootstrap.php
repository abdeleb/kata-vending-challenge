<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use VendingMachine\Application\Service\VendingMachineService;
use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Change\BacktrackingChangeStrategy;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\VendingMachine;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

/**
 * The CLI composition root: wires the whole stack into a ready-to-run application.
 *
 * It is the single place that names concrete classes — the in-memory repository, the backtracking
 * change strategy, the parser and formatter — so the rest of the code depends only on ports and
 * interfaces. Keeping it here, in a tested and statically analyzed class, lets bin/vending stay a
 * three-line shim that just loads the autoloader and runs.
 *
 * The default machine is provisioned through its own service operations, exactly as a technician would
 * with SERVICE / SET-CHANGE / RESTOCK, so the binary serves the brief's examples out of the box. The
 * catalog, change float and initial stock are demo seed data; a real deployment would load them from
 * configuration or a database behind the same repository port.
 */
final class CliBootstrap
{
    public static function defaultApplication(): CliApplication
    {
        $repository = new InMemoryVendingMachineRepository(self::provisionedMachine());
        $service = new VendingMachineService($repository, new BacktrackingChangeStrategy());
        $interpreter = new CommandInterpreter($service, new CoinParser(), new OutputFormatter());

        return new CliApplication($interpreter, new ErrorMapper());
    }

    private static function provisionedMachine(): VendingMachine
    {
        $machine = VendingMachine::operational(self::catalog());

        $machine->enterService();
        $machine->setAvailableChange(
            CoinSet::empty()
                ->add(Coin::TWENTY_FIVE, 10)
                ->add(Coin::TEN, 10)
                ->add(Coin::FIVE, 10),
        );
        $machine->restockItems(ItemInventory::fromQuantities([
            'WATER' => 10,
            'SODA' => 10,
            'JUICE' => 10,
        ]));
        $machine->leaveService();

        return $machine;
    }

    private static function catalog(): Catalog
    {
        return Catalog::of(
            Product::create('WATER', Money::fromCents(65)),
            Product::create('SODA', Money::fromCents(150)),
            Product::create('JUICE', Money::fromCents(90)),
        );
    }
}
