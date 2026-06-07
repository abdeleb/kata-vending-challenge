<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use function fgets;
use function fwrite;

use const PHP_EOL;

use RuntimeException;
use VendingMachine\Domain\Exception\IllegalState;

/**
 * The CLI run loop: read commands line by line, render their output, and turn recoverable errors into
 * stderr messages and a stable exit code.
 *
 * Each line is handed to the interpreter; its rendered output goes to the output stream. A recoverable
 * error is written to the error stream and processing continues with the next line, so one rejected
 * command never aborts the session: the RuntimeException family (a bad coin or command, or a domain
 * refusal the machine decided) plus an IllegalState, which a human at this prompt raises by typing a
 * command in the wrong mode (e.g. GET-WATER while servicing). Any other failure -- a broken invariant
 * or an Error -- is deliberately not caught and bubbles with its stack trace rather than being dressed
 * up as a user error.
 *
 * The process exits 0 when every line succeeded, otherwise with the code of the most recent recoverable
 * error, so a piped batch fails the shell whenever a command was refused.
 */
final class CliApplication
{
    public function __construct(
        private readonly CommandInterpreter $interpreter,
        private readonly ErrorMapper $errorMapper,
    ) {
    }

    /**
     * @param resource $input
     * @param resource $output
     * @param resource $errors
     */
    public function run($input, $output, $errors): int
    {
        $exitCode = ErrorMapper::EXIT_SUCCESS;

        while (($line = fgets($input)) !== false) {
            try {
                foreach ($this->interpreter->interpret($line) as $rendered) {
                    fwrite($output, $rendered . PHP_EOL);
                }
            } catch (RuntimeException | IllegalState $error) {
                fwrite($errors, $error->getMessage() . PHP_EOL);
                $exitCode = $this->errorMapper->exitCodeFor($error);
            }
        }

        return $exitCode;
    }
}
