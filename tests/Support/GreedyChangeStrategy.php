<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Support;

use VendingMachine\Domain\Change\ChangeStrategy;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;

/**
 * A deliberately incomplete ChangeStrategy kept only as a contrast fixture.
 *
 * It takes the largest dispensable coin that fits and never reconsiders, so with a finite stock it
 * can fail to find change that actually exists (e.g. 30c from [25x1, 10x3]). It lives under the
 * test-only autoload namespace, so it can never be wired in production — a guarantee enforced by the
 * build (autoload-dev is dropped with --no-dev), not by a docblock convention. Its purpose is to
 * prove, executably, why backtracking is needed and to give the contract test a second implementation.
 *
 * It is still *sound*: any change it does return is correct. It only lacks *completeness*.
 */
final class GreedyChangeStrategy implements ChangeStrategy
{
    public function computeChange(Money $due, CoinSet $available): ?CoinSet
    {
        $remaining = $due->cents;
        $change = CoinSet::empty();

        foreach ($this->dispensableDenominationsDescending() as $coin) {
            $value = $coin->valueInCents();
            $take = min(intdiv($remaining, $value), $available->count($coin));

            if ($take > 0) {
                $change = $change->add($coin, $take);
                $remaining -= $take * $value;
            }
        }

        return $remaining === 0 ? $change : null;
    }

    /**
     * @return list<Coin>
     */
    private function dispensableDenominationsDescending(): array
    {
        $coins = array_values(array_filter(
            Coin::cases(),
            static fn (Coin $coin): bool => $coin->isDispensableAsChange(),
        ));

        usort($coins, static fn (Coin $a, Coin $b): int => $b->valueInCents() <=> $a->valueInCents());

        return $coins;
    }
}
