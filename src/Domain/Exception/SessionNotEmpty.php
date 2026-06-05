<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

/**
 * Raised when the machine is asked to enter Service while the customer still has coins in the
 * retention tray.
 *
 * This is a real physical situation, not a usage bug: a customer can walk away mid-purchase leaving
 * coins behind, and only then does a technician arrive. So it is a recoverable domain error — the
 * documented recovery is to return the coins (RETURN-COIN) and retry. Keeping it out of
 * enterService() leaves that transition single-purpose; auto-returning the coins from within the
 * transition was considered and rejected, to avoid overloading it with a refund side effect.
 */
final class SessionNotEmpty extends DomainException
{
}
