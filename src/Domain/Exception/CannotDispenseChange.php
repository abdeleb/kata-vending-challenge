<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

/**
 * Raised when a sale is legal on price and stock, but the machine cannot compose the exact change
 * from its available, dispensable coins.
 *
 * The sale fails closed: it is rejected and the customer's coins stay in the session, so the machine
 * never short-changes anyone rather than approximating the change. A DomainException the CLI boundary
 * maps to a user-facing message.
 */
final class CannotDispenseChange extends DomainException
{
}
