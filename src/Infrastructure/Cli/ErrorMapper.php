<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use Throwable;
use VendingMachine\Domain\Exception\IllegalState;

/**
 * Maps an error the run loop caught to the process exit code the CLI reports, keeping the exit-code
 * contract in a single place.
 *
 * The code is drawn off the exception type. A valid command the machine refused for a domain reason —
 * out of stock, insufficient funds, no change, a busy tray (the DomainException family) — maps to the
 * operational error code (1). Malformed or inapplicable input maps to the usage code (2): an unaccepted
 * coin or an unrecognized command (InvalidCoin / InvalidCommand), and a command issued in the wrong
 * mode (IllegalState) — because the CLI's driver is a human typing commands, a wrong-mode command (e.g.
 * GET-WATER while servicing) is an ordinary usage mistake to report and recover from, not the
 * programming bug it would be behind a correct programmatic driver. Genuinely unexpected failures (a
 * broken invariant, any Error) are not in this map: the run loop never catches them, so they still
 * bubble with a stack trace.
 */
final class ErrorMapper
{
    /** A command completed without error. */
    public const EXIT_SUCCESS = 0;

    /** A valid command the machine refused for a domain reason. */
    public const EXIT_DOMAIN_ERROR = 1;

    /** Malformed input: an unrecognized command or a token that is not an accepted coin. */
    public const EXIT_INPUT_ERROR = 2;

    public function exitCodeFor(Throwable $error): int
    {
        return match (true) {
            $error instanceof InvalidCoin,
            $error instanceof InvalidCommand,
            $error instanceof IllegalState => self::EXIT_INPUT_ERROR,
            default => self::EXIT_DOMAIN_ERROR,
        };
    }
}
