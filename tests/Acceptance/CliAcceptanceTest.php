<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Acceptance;

use function fclose;
use function fwrite;
use function is_resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function proc_close;
use function proc_open;
use function stream_get_contents;

use VendingMachine\Infrastructure\Cli\CliBootstrap;
use VendingMachine\Infrastructure\Cli\ErrorMapper;
use VendingMachine\Tests\Support\InMemoryStreams;

final class CliAcceptanceTest extends TestCase
{
    use InMemoryStreams;

    #[DataProvider('briefExamples')]
    public function test_the_default_machine_reproduces_a_brief_example(string $input, string $expected): void
    {
        $output = $this->memoryStream();

        $exitCode = CliBootstrap::defaultApplication()->run(
            $this->memoryStream($input),
            $output,
            $this->memoryStream(),
        );

        self::assertSame(ErrorMapper::EXIT_SUCCESS, $exitCode);
        self::assertSame($expected, $this->contentsOf($output));
    }

    /**
     * The three command sequences from the brief, with their expected output.
     *
     * @return array<string, array{string, string}>
     */
    public static function briefExamples(): array
    {
        return [
            'exact payment dispenses the product'        => ["1, 0.25, 0.25, GET-SODA\n", "SODA\n"],
            'a return hands the inserted coins back'     => ["0.10, 0.10, RETURN-COIN\n", "0.10, 0.10\n"],
            'overpayment returns the product and change' => ["1, GET-WATER\n", "WATER, 0.25, 0.10\n"],
        ];
    }

    public function test_the_packaged_binary_serves_a_product_over_real_pipes(): void
    {
        $pipes = [];
        $process = proc_open(
            ['php', __DIR__ . '/../../bin/vending'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('Could not start the vending binary.');
        }

        fwrite($pipes[0], "1, 0.25, 0.25, GET-SODA\n");
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertSame("SODA\n", $stdout);
        self::assertSame(0, $exitCode);
    }
}
