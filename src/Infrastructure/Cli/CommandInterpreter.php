<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

use VendingMachine\Application\Port\MachineDriver;

/**
 * Translates a line of CLI text into calls on the driving port and renders the result back to text.
 *
 * A line is a comma-separated stream of tokens executed left to right, which is exactly how the brief's
 * examples read ("1, 0.25, 0.25, GET-SODA"). Each token maps to one use case: a decimal is a coin to
 * insert, GET-<code> selects an item, RETURN-COIN returns the tray. Coins produce no output; a sale or
 * a return produces one rendered line. Classification routes anything starting with a digit to the coin
 * parser (so "0.30" still fails as an InvalidCoin with its precise message) and everything else to a
 * command keyword, so an unknown word is an InvalidCommand rather than a bad coin.
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
     * inserted coins or was blank).
     *
     * @return list<string>
     */
    public function interpret(string $line): array
    {
        $output = [];

        foreach ($this->tokenize($line) as $token) {
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
