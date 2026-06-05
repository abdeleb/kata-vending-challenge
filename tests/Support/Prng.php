<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Support;

use LogicException;

/**
 * A tiny deterministic pseudo-random generator (a linear congruential generator).
 *
 * Seeding it makes a command sequence exactly reproducible on any platform — unlike mt_rand(),
 * whose stream depends on the engine — so a failing seed in the money-conservation fuzz replays the
 * identical sequence for debugging. This is what lets that test stay an honest *seeded fuzz* rather
 * than leaning on a global RNG. Reproducibility rides on the pinned 64-bit PHP runtime (the
 * arithmetic below stays within 31 bits, so it never overflows there).
 */
final class Prng
{
    private int $state;

    public function __construct(int $seed)
    {
        $masked = $seed & 0x7FFFFFFF;
        // A zero state would stay zero forever under this recurrence, so fold it to a fixed nonzero seed.
        $this->state = $masked === 0 ? 1 : $masked;
    }

    /**
     * The next pseudo-random integer in [0, $bound).
     */
    public function nextBelow(int $bound): int
    {
        if ($bound <= 0) {
            throw new LogicException("Bound must be positive, got {$bound}.");
        }

        // Numerical Recipes constants; the mask keeps the product within 31 bits so it never overflows.
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $this->state % $bound;
    }
}
