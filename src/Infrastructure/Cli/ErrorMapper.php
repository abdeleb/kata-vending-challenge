<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use Throwable;

/**
 * Maps a recoverable error to the process exit code the CLI reports, keeping the exit-code contract in
 * a single place.
 *
 * The exception hierarchy already separates the two error kinds: the CLI catches the recoverable
 * RuntimeException family and never catches IllegalState (a LogicException), so a programming bug
 * bubbles out with a stack trace instead of being dressed up as a user error. Within the recoverable
 * family this draws a further, conventional line: malformed input — an unaccepted coin or an
 * unrecognized command — is a usage error (code 2), while every other recoverable error is a valid
 * command the machine refused for a domain reason (out of stock, insufficient funds, no change, a busy
 * tray), which is the DomainException family and maps to the operational error code (code 1).
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
            $error instanceof InvalidCoin, $error instanceof InvalidCommand => self::EXIT_INPUT_ERROR,
            default => self::EXIT_DOMAIN_ERROR,
        };
    }
}
