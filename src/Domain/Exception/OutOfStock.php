<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

use RuntimeException;

/**
 * Raised when a product is selected (or dispensed) while its stock is exhausted.
 *
 * A recoverable runtime condition the customer can hit, not a broken invariant — so it extends
 * RuntimeException and is mapped to a user-facing CLI message, like UnknownProduct.
 */
final class OutOfStock extends RuntimeException
{
}
