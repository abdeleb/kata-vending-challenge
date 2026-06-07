<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use function intdiv;
use function sprintf;

use VendingMachine\Domain\Money\Coin;

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
}
