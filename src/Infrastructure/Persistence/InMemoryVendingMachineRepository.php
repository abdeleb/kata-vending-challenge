<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Persistence;

use VendingMachine\Domain\Machine\VendingMachine;
use VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * In-memory adapter for the repository port: it holds the aggregate in a single field.
 *
 * It is seeded at construction because, unlike a database-backed adapter, it has no external store to
 * read the machine's initial state from — a Doctrine/PDO adapter would read it from the database.
 *
 * Both save() and load() clone the aggregate so the stored value is an isolated snapshot, exactly as
 * a real database behaves: load() yields a fresh copy and save() captures the state at the moment of
 * the call. This matters because the aggregate is mutable — a by-reference store would let a caller
 * mutate persisted state without saving (and would make tests pass that a real database would fail),
 * which is the leak a faithful fake must not have. A shallow clone suffices: every field of the
 * aggregate is an immutable value object or enum, so the copy shares no mutable state with the
 * original — the aggregate only ever changes by reassigning a field to a new value object.
 */
final class InMemoryVendingMachineRepository implements VendingMachineRepository
{
    public function __construct(private VendingMachine $machine)
    {
    }

    public function load(): VendingMachine
    {
        return clone $this->machine;
    }

    public function save(VendingMachine $machine): void
    {
        $this->machine = clone $machine;
    }
}
