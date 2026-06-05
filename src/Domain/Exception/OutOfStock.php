<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

/**
 * Raised when a product is selected (or dispensed) while its stock is exhausted.
 *
 * A recoverable runtime condition the customer can hit, not a broken invariant — a DomainException
 * the CLI boundary maps to a user-facing message, like UnknownProduct.
 */
final class OutOfStock extends DomainException
{
}
