<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Repository;

use VendingMachine\Domain\Machine\VendingMachine;

/**
 * The persistence port for the vending machine aggregate: its load/save lifecycle.
 *
 * This is a driven (output) port owned by the domain — the application calls it, infrastructure
 * implements it. It isolates the aggregate's lifecycle: the domain never knows where the machine is
 * stored, only that it can be loaded and saved. There is a single physical cabinet, so the port
 * models one machine and load() is total (the machine always exists), rather than a find-by-id that
 * could miss. Swapping persistence — InMemory today, Doctrine/PDO/Redis tomorrow — is a new class
 * behind this port with no change to the domain.
 */
interface VendingMachineRepository
{
    public function load(): VendingMachine;

    public function save(VendingMachine $machine): void;
}
