<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

use RuntimeException;

/**
 * Base for recoverable domain errors — the typed, non-happy channel of the domain.
 *
 * Every subclass is an expected runtime condition a customer or technician can legitimately hit (an
 * unknown product, an empty drawer, coins still in the tray), never a programming bug. The CLI
 * boundary catches this single category and maps it to a user-facing message with a stable exit
 * code. Programming/state bugs use a LogicException (IllegalState) instead and bubble unmapped, so
 * the two never share a catch.
 */
abstract class DomainException extends RuntimeException
{
}
