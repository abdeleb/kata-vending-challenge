<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Exception;

use LogicException;

/**
 * Raised when an operation is invoked in the wrong mode — a customer action while the machine is in
 * Service, or a service action while it is Operational.
 *
 * A correct driver never issues such a call, so this signals a programming bug rather than a
 * user-facing condition: it extends LogicException and is deliberately left to bubble unmapped,
 * unlike the recoverable DomainException hierarchy the CLI catches and translates.
 */
final class IllegalState extends LogicException
{
}
