<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use RuntimeException;

/**
 * The CLI was given a token that is not an accepted coin — malformed (".25", "1,25") or a value that
 * is not one of the machine's denominations ("0.30", "2").
 *
 * It lives in the delivery layer, not the domain: rejecting input text is an adapter concern, and the
 * domain only ever sees a valid Coin. It is a recoverable, user-facing error (a RuntimeException, like
 * the domain's recoverable category), so the CLI error mapper turns it into a message and a stable
 * exit code rather than letting it bubble as a crash.
 */
final class InvalidCoin extends RuntimeException
{
}
