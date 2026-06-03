<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

use RuntimeException;

/**
 * Raised when a customer selects a product code that the catalog does not contain.
 *
 * A recoverable user error (not a broken invariant), so it extends RuntimeException and will
 * be mapped to the CLI as a user-facing message. A common DomainException base is introduced
 * later, once several domain exceptions exist and catching them by category earns its keep.
 */
final class UnknownProduct extends RuntimeException
{
}
