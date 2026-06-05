<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

/**
 * Raised when a customer selects a product code that the catalog does not contain.
 *
 * A recoverable user error, not a broken invariant — a DomainException the CLI boundary maps to a
 * user-facing message.
 */
final class UnknownProduct extends DomainException
{
}
