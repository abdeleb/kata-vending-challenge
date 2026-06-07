<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Integration\Cli;

use function fopen;
use function fwrite;

use PHPUnit\Framework\TestCase;

use function rewind;
use function stream_get_contents;

use VendingMachine\Application\Service\VendingMachineService;
use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Change\BacktrackingChangeStrategy;
use VendingMachine\Domain\Exception\IllegalState;
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

final class CliApplicationTest extends TestCase
{
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

    public function test_a_driver_bug_is_not_caught_and_bubbles_out(): void
    {
        $application = $this->stockedApplication();

        $this->expectException(IllegalState::class);

        $application->run($this->memoryStream("END-SERVICE\n"), $this->memoryStream(), $this->memoryStream());
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

        $repository = new InMemoryVendingMachineRepository($machine);
        $service = new VendingMachineService($repository, new BacktrackingChangeStrategy());
        $interpreter = new CommandInterpreter($service, new CoinParser(), new OutputFormatter());

        return new CliApplication($interpreter, new ErrorMapper());
    }

    /**
     * @return resource
     */
    private function memoryStream(string $contents = '')
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            self::fail('Could not open an in-memory stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contentsOf($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);

        return $contents === false ? '' : $contents;
    }
}
