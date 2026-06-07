<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use RuntimeException;

/**
 * The CLI was given a token that is not a recognized command — neither a coin, nor GET-<code>,
 * RETURN-COIN, or one of the service verbs.
 *
 * Like InvalidCoin it lives in the delivery layer, not the domain: deciding what counts as a valid
 * command word is a concern of this adapter's grammar, and the domain never sees the raw text. It is a
 * recoverable, user-facing error (a RuntimeException), so the CLI error mapper turns it into a message
 * and a stable exit code rather than letting it bubble as a crash.
 */
final class InvalidCommand extends RuntimeException
{
}
