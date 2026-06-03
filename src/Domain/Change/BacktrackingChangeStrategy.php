<?php

declare(strict_types=1);

namespace VendingMachine\Domain\Change;

use function array_slice;

use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;

/**
 * Composes exact change from a finite coin stock by exhaustive backtracking.
 *
 * Unlike a greedy pick, it explores alternative combinations, so it finds change whenever one
 * exists given the available coins — e.g. 30c from [25x1, 10x3] is returned as 10+10+10, which
 * greedy cannot reach after taking the 25. It draws only from coins that are dispensable as
 * change, so the 100c coin is accepted as payment but is never returned.
 */
final class BacktrackingChangeStrategy implements ChangeStrategy
{
    public function computeChange(Money $due, CoinSet $available): ?CoinSet
    {
        return $this->compose($due->cents, $available, $this->dispensableDenominationsDescending());
    }

    /**
     * Try to make $remaining cents from the given denominations (highest first), drawing no
     * more of each coin than $available holds. Recurses one denomination at a time; each call
     * either drops a denomination or reaches a base case, so the search always terminates.
     *
     * @param list<Coin> $denominations dispensable coins, highest value first
     */
    private function compose(int $remaining, CoinSet $available, array $denominations): ?CoinSet
    {
        if ($remaining === 0) {
            return CoinSet::empty();
        }

        if ($denominations === []) {
            return null;
        }

        $coin = $denominations[0];
        $rest = array_slice($denominations, 1);
        $value = $coin->valueInCents();
        $most = min(intdiv($remaining, $value), $available->count($coin));

        for ($count = $most; $count >= 0; $count--) {
            $change = $this->compose($remaining - ($count * $value), $available, $rest);

            if ($change !== null) {
                return $count > 0 ? $change->add($coin, $count) : $change;
            }
        }

        return null;
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
