<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

/**
 * Raised when a product is selected but the inserted coins do not cover its price.
 *
 * A recoverable runtime condition, not a broken invariant: the customer's session is left untouched,
 * so they can insert more coins and retry. A DomainException the CLI boundary maps to a user-facing
 * message, like UnknownProduct and OutOfStock.
 */
final class InsufficientFunds extends DomainException
{
}
