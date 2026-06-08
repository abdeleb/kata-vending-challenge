<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use function preg_match;
use function sprintf;
use function str_pad;

use const STR_PAD_RIGHT;

use function strlen;

use VendingMachine\Domain\Money\Coin;

/**
 * Parses a single decimal coin token (e.g. "0.25", "1") into a Coin, at the CLI boundary.
 *
 * Money is integer cents everywhere inside the application; the only place a float could sneak in is
 * here, parsing decimal text. So the conversion is purely string-based and integer arithmetic — never
 * floatval, which would make "0.29" become 28 cents (0.29 * 100 = 28.999...). The token must be a
 * whole number with one or two optional decimal places, and the resulting cents must be one of the
 * machine's denominations; anything else is an InvalidCoin. Tokenizing and trimming a command line is
 * the caller's job, so a token with surrounding whitespace is rejected rather than silently cleaned.
 */
final class CoinParser
{
    public function parse(string $token): Coin
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $token, $matches) !== 1) {
            throw new InvalidCoin(sprintf('"%s" is not a valid amount.', $token));
        }

        // The regex leaves the integer part unbounded; reject an oversized one before converting. Such a
        // token can never be a coin (the largest is 1.00), and left unchecked "euros * 100" below would
        // overflow int into a float — PHP promotes silently — which then reaches Coin::tryFrom (typed int
        // under strict_types) as an uncatchable TypeError instead of the recoverable InvalidCoin this
        // boundary guarantees. Three digits is a generous bound that stays well clear of that overflow.
        if (strlen($matches[1]) > 3) {
            throw new InvalidCoin(sprintf('"%s" is not an accepted coin.', $token));
        }

        $cents = ((int) $matches[1]) * 100;

        if (isset($matches[2])) {
            $cents += (int) str_pad($matches[2], 2, '0', STR_PAD_RIGHT);
        }

        return Coin::tryFrom($cents)
            ?? throw new InvalidCoin(sprintf('"%s" is not an accepted coin.', $token));
    }
}
