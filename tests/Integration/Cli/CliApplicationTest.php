<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Integration\Cli;

use PHPUnit\Framework\TestCase;
use VendingMachine\Application\Service\VendingMachineService;
use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Change\BacktrackingChangeStrategy;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\VendingMachine;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;
use VendingMachine\Infrastructure\Cli\CliApplication;
use VendingMachine\Infrastructure\Cli\CoinParser;
use VendingMachine\Infrastructure\Cli\CommandInterpreter;
use VendingMachine\Infrastructure\Cli\ErrorMapper;
use VendingMachine\Infrastructure\Cli\OutputFormatter;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;
use VendingMachine\Tests\Support\InMemoryStreams;

final class CliApplicationTest extends TestCase
{
    use InMemoryStreams;

    public function test_a_successful_purchase_is_written_to_stdout_with_a_zero_exit_code(): void
    {
        $application = $this->stockedApplication();
        $output = $this->memoryStream();
        $errors = $this->memoryStream();

        $exitCode = $application->run($this->memoryStream("1, 0.25, 0.25, GET-SODA\n"), $output, $errors);

        self::assertSame(ErrorMapper::EXIT_SUCCESS, $exitCode);
        self::assertSame("SODA\n", $this->contentsOf($output));
        self::assertSame('', $this->contentsOf($errors));
    }

    public function test_change_is_rendered_after_the_product(): void
    {
        $application = $this->stockedApplication();
        $output = $this->memoryStream();

        $exitCode = $application->run($this->memoryStream("1, GET-WATER\n"), $output, $this->memoryStream());

        self::assertSame(ErrorMapper::EXIT_SUCCESS, $exitCode);
        self::assertSame("WATER, 0.25, 0.10\n", $this->contentsOf($output));
    }

    public function test_a_domain_refusal_goes_to_stderr_and_sets_the_domain_exit_code(): void
    {
        $application = $this->stockedApplication();
        $output = $this->memoryStream();
        $errors = $this->memoryStream();

        $exitCode = $application->run($this->memoryStream("GET-SODA\n"), $output, $errors);

        self::assertSame(ErrorMapper::EXIT_DOMAIN_ERROR, $exitCode);
        self::assertSame('', $this->contentsOf($output));
        self::assertStringContainsString('SODA', $this->contentsOf($errors));
    }

    public function test_malformed_input_goes_to_stderr_and_sets_the_input_exit_code(): void
    {
        $application = $this->stockedApplication();
        $errors = $this->memoryStream();

        $exitCode = $application->run($this->memoryStream("FOO\n"), $this->memoryStream(), $errors);

        self::assertSame(ErrorMapper::EXIT_INPUT_ERROR, $exitCode);
        self::assertStringContainsString('FOO', $this->contentsOf($errors));
    }

    public function test_it_processes_every_line_and_reports_the_most_recent_error(): void
    {
        $application = $this->stockedApplication();
        $output = $this->memoryStream();

        $exitCode = $application->run(
            $this->memoryStream("1, 0.25, 0.25, GET-SODA\nGET-WATER\n"),
            $output,
            $this->memoryStream(),
        );

        self::assertSame("SODA\n", $this->contentsOf($output));
        self::assertSame(ErrorMapper::EXIT_DOMAIN_ERROR, $exitCode);
    }

    public function test_a_wrong_mode_command_is_recoverable_and_does_not_abort_the_session(): void
    {
        $application = $this->stockedApplication();
        $output = $this->memoryStream();
        $errors = $this->memoryStream();

        // END-SERVICE while the machine is operational is a wrong-mode command a human can type at the
        // prompt. It is reported to stderr and the loop carries on to the next line (which still sells),
        // rather than crashing the process with an uncaught fatal.
        $exitCode = $application->run(
            $this->memoryStream("END-SERVICE\n1, 0.25, 0.25, GET-SODA\n"),
            $output,
            $errors,
        );

        self::assertSame("SODA\n", $this->contentsOf($output));
        self::assertStringContainsString('mode', $this->contentsOf($errors));
        self::assertSame(ErrorMapper::EXIT_INPUT_ERROR, $exitCode);
    }

    public function test_a_full_operator_then_customer_session_runs_over_the_real_streams(): void
    {
        $application = $this->operationalApplication();
        $output = $this->memoryStream();
        $errors = $this->memoryStream();

        // A technician provisions the machine line by line, leaves service, then a customer buys and is
        // paid the change just stocked. This exercises the load -> mutate -> save thread per line for the
        // service commands end to end through the run loop, against the real service and repository --
        // not the spy the interpreter unit test uses.
        $exitCode = $application->run(
            $this->memoryStream(
                "SERVICE\n"
                . "RESTOCK, WATER:1\n"
                . "SET-CHANGE, 0.25, 0.10\n"
                . "END-SERVICE\n"
                . "1, GET-WATER\n",
            ),
            $output,
            $errors,
        );

        self::assertSame(ErrorMapper::EXIT_SUCCESS, $exitCode);
        self::assertSame('', $this->contentsOf($errors));
        self::assertSame("WATER, 0.25, 0.10\n", $this->contentsOf($output));
    }

    private function stockedApplication(): CliApplication
    {
        $catalog = Catalog::of(
            Product::create('SODA', Money::fromCents(150)),
            Product::create('WATER', Money::fromCents(65)),
        );

        $machine = VendingMachine::operational($catalog);
        $machine->enterService();
        $machine->setAvailableChange(
            CoinSet::empty()->add(Coin::TWENTY_FIVE, 10)->add(Coin::TEN, 10)->add(Coin::FIVE, 10),
        );
        $machine->restockItems(ItemInventory::fromQuantities(['SODA' => 5, 'WATER' => 5]));
        $machine->leaveService();

        return $this->applicationFor($machine);
    }

    private function operationalApplication(): CliApplication
    {
        $catalog = Catalog::of(
            Product::create('SODA', Money::fromCents(150)),
            Product::create('WATER', Money::fromCents(65)),
        );

        return $this->applicationFor(VendingMachine::operational($catalog));
    }

    private function applicationFor(VendingMachine $machine): CliApplication
    {
        $repository = new InMemoryVendingMachineRepository($machine);
        $service = new VendingMachineService($repository, new BacktrackingChangeStrategy());
        $interpreter = new CommandInterpreter($service, new CoinParser(), new OutputFormatter());

        return new CliApplication($interpreter, new ErrorMapper());
    }
}
