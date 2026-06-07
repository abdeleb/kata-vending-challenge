<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use function array_reverse;
use function implode;
use function intdiv;
use function sprintf;

use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;

/**
 * Renders a coin as the canonical decimal string the CLI prints (25c -> "0.25"), the inverse of
 * CoinParser across the CLI boundary.
 *
 * Money is integer cents everywhere inside the application, so this stays integer-only: sprintf splits
 * the value into whole euros (%d) and the two-digit cent remainder (%02d). It never divides by 100 into
 * a float (number_format($cents / 100, 2)), which is the output-side twin of the "0.29" rounding trap
 * the parser avoids. The parser is liberal in what it accepts ("1", "1.00", "0.1", "0.10"); the
 * formatter emits one canonical form per amount — always two decimal places — which always round-trips
 * back through the parser.
 */
final class OutputFormatter
{
    public function formatCoin(Coin $coin): string
    {
        $cents = $coin->valueInCents();

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * Render a set of coins as a comma-separated list, highest denomination first (e.g. "0.25, 0.10"),
     * the form the CLI prints for a sale's change and for returned coins. An empty set renders to "".
     *
     * The CoinSet keeps its contents private and exposes no iterator: formatting is presentation, not a
     * domain operation, so it does not earn a place on the value object (unlike plus/minus, which are
     * domain arithmetic). Instead this adapter — which legitimately knows the denominations — walks the
     * fixed coin set and asks count() per denomination, leaving the CoinSet's surface minimal. The order
     * is a presentation choice owned here, not by the unordered multiset.
     */
    public function formatCoins(CoinSet $coins): string
    {
        $parts = [];

        foreach (array_reverse(Coin::cases()) as $coin) {
            $count = $coins->count($coin);

            for ($i = 0; $i < $count; ++$i) {
                $parts[] = $this->formatCoin($coin);
            }
        }

        return implode(', ', $parts);
    }
}
