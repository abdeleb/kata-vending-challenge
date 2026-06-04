<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Machine;

/**
 * The two mutually exclusive modes the machine can be in, toggled by the service person.
 *
 * Customer actions are allowed only in Operational and service actions only in Service; the aggregate
 * enforces these as bidirectional guards. Conditions like "exact change only" or "sold out" are NOT
 * modes — they are derived from the current inventory and modelled as policies, not as state, so there
 * is no artificial transition to recompute on every operation.
 */
enum OperationalMode
{
    case Operational;
    case Service;
}
