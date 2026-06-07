<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

use VendingMachine\Application\Port\MachineDriver;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Money\CoinSet;

/**
 * Translates a line of CLI text into calls on the driving port and renders the result back to text.
 *
 * A line is a comma-separated stream of tokens executed left to right, which is exactly how the brief's
 * examples read ("1, 0.25, 0.25, GET-SODA"). Each token maps to one use case: a decimal is a coin to
 * insert, GET-<code> selects an item, RETURN-COIN returns the tray, and SERVICE / END-SERVICE move in
 * and out of service mode. SET-CHANGE and RESTOCK are the two variadic service commands: they consume
 * the rest of their line as operands (coin tokens, or CODE:quantity pairs), matching the aggregate's
 * set-semantics — the technician declares the whole drawer or shelf at once. Being variadic, each must
 * be the last command on its line; a recognized command among their operands is rejected rather than
 * silently swallowed.
 *
 * Coins and service commands produce no output; a sale or a return produces one rendered line.
 * Classification routes anything starting with a digit to the coin parser (so "0.30" still fails as an
 * InvalidCoin with its precise message) and everything else to a command keyword, so an unknown word is
 * an InvalidCommand rather than a bad coin.
 *
 * It depends only on the MachineDriver port, never on a concrete service, so the same interpreter works
 * over any wiring; turning exceptions into exit codes is left to the error mapper, keeping this class to
 * the single job of parse-route-render.
 */
final class CommandInterpreter
{
    public function __construct(
        private readonly MachineDriver $driver,
        private readonly CoinParser $coinParser,
        private readonly OutputFormatter $formatter,
    ) {
    }

    /**
     * Run every command on a line in order and return the lines it produced (empty when the line only
     * inserted coins, ran a service command or was blank).
     *
     * @return list<string>
     */
    public function interpret(string $line): array
    {
        $tokens = $this->tokenize($line);
        $output = [];

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];

            // The two variadic service commands take the rest of the line as their operands, so each
            // must be the last command on the line; a trailing command is rejected, not swallowed.
            if ($token === 'SET-CHANGE') {
                $operands = array_slice($tokens, $i + 1);
                $this->assertNoTrailingCommand($operands, 'SET-CHANGE');
                $this->driver->setAvailableChange($this->parseChange($operands));

                break;
            }

            if ($token === 'RESTOCK') {
                $operands = array_slice($tokens, $i + 1);
                $this->assertNoTrailingCommand($operands, 'RESTOCK');
                $this->driver->restockItems($this->parseStock($operands));

                break;
            }

            $rendered = $this->dispatch($token);

            if ($rendered !== null) {
                $output[] = $rendered;
            }
        }

        return $output;
    }

    private function dispatch(string $token): ?string
    {
        if (preg_match('/^\d/', $token) === 1) {
            $this->driver->insertCoin($this->coinParser->parse($token));

            return null;
        }

        if ($token === 'RETURN-COIN') {
            return $this->formatter->formatCoins($this->driver->returnCoins());
        }

        if ($token === 'SERVICE') {
            $this->driver->enterService();

            return null;
        }

        if ($token === 'END-SERVICE') {
            $this->driver->leaveService();

            return null;
        }

        if (str_starts_with($token, 'GET-')) {
            $code = substr($token, 4);

            if ($code === '') {
                throw new InvalidCommand('A GET command needs a product code, e.g. "GET-SODA".');
            }

            return $this->formatter->formatSale($this->driver->selectItem($code));
        }

        throw new InvalidCommand(sprintf('"%s" is not a recognized command.', $token));
    }

    /**
     * Build the change drawer from coin tokens, reusing the boundary coin parser so an out-of-range
     * value fails as the same InvalidCoin a customer would get. An empty list declares an empty drawer.
     *
     * @param list<string> $tokens
     */
    private function parseChange(array $tokens): CoinSet
    {
        $coins = CoinSet::empty();

        foreach ($tokens as $token) {
            $coins = $coins->add($this->coinParser->parse($token));
        }

        return $coins;
    }

    /**
     * Build the product stock from CODE:quantity pairs (e.g. "SODA:5"). An empty list declares an empty
     * shelf; a malformed pair is an InvalidCommand.
     *
     * @param list<string> $tokens
     */
    private function parseStock(array $tokens): ItemInventory
    {
        $quantities = [];

        foreach ($tokens as $token) {
            if (preg_match('/^([^:]+):(\d+)$/', $token, $matches) !== 1) {
                throw new InvalidCommand(sprintf('"%s" is not a valid "CODE:quantity" pair.', $token));
            }

            $quantities[$matches[1]] = (int) $matches[2];
        }

        return ItemInventory::fromQuantities($quantities);
    }

    /**
     * A variadic service command consumes the rest of the line as operands, so it must be the last
     * command on its line. Reject a recognized command among the operands with a clear message rather
     * than silently swallowing it (or letting it fail later as a confusing bad-operand error).
     *
     * @param list<string> $operands
     */
    private function assertNoTrailingCommand(array $operands, string $command): void
    {
        foreach ($operands as $operand) {
            if ($this->isCommandToken($operand)) {
                throw new InvalidCommand(sprintf(
                    '%s consumes the rest of the line as its operands, so "%s" must go on its own line.',
                    $command,
                    $operand,
                ));
            }
        }
    }

    private function isCommandToken(string $token): bool
    {
        return in_array($token, ['RETURN-COIN', 'SERVICE', 'END-SERVICE', 'SET-CHANGE', 'RESTOCK'], true)
            || str_starts_with($token, 'GET-');
    }

    /**
     * Split a line on commas, trim each token and drop empty ones, so trailing or doubled commas and
     * surrounding whitespace are tolerated rather than reaching the strict coin parser.
     *
     * @return list<string>
     */
    private function tokenize(string $line): array
    {
        $tokens = array_map(trim(...), explode(',', $line));

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }
}
