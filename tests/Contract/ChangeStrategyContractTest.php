<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Change\BacktrackingChangeStrategy;
use VendingMachine\Domain\Change\ChangeStrategy;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;
use VendingMachine\Tests\Support\GreedyChangeStrategy;

/**
 * The contract every ChangeStrategy must honour, checked against both implementations.
 *
 * It pins *soundness*: whatever a strategy returns must be correct — change that sums exactly to
 * the amount due, drawn only from coins that are available and dispensable as change. It does not
 * pin *completeness* (finding change whenever one exists): that is where the implementations
 * legitimately diverge, and the divergence is demonstrated separately below.
 */
final class ChangeStrategyContractTest extends TestCase
{
    #[DataProvider('soundnessCases')]
    public function test_any_returned_change_is_sound(ChangeStrategy $strategy, Money $due, CoinSet $available): void
    {
        $change = $strategy->computeChange($due, $available);

        if ($change === null) {
            // null means "no change possible"; the contract only constrains a returned CoinSet.
            $this->expectNotToPerformAssertions();

            return;
        }

        self::assertTrue($change->total()->equals($due), 'change must sum exactly to the amount due');

        foreach (Coin::cases() as $coin) {
            self::assertLessThanOrEqual(
                $available->count($coin),
                $change->count($coin),
                'change must never use more coins than are available',
            );

            if ($change->count($coin) > 0) {
                self::assertTrue($coin->isDispensableAsChange(), 'change must contain only dispensable coins');
            }
        }
    }

    public function test_greedy_is_stranded_where_backtracking_succeeds(): void
    {
        // 30c from [25x1, 10x3] — the canonical contrast. A greedy pick commits to the 25 and cannot
        // make the remaining 5; backtracking skips it and returns 10+10+10. Same contract, different
        // completeness — which is why the abstraction (and this contrast fixture) earns its keep.
        $due = Money::fromCents(30);
        $available = CoinSet::empty()->add(Coin::TWENTY_FIVE, 1)->add(Coin::TEN, 3);

        self::assertNull((new GreedyChangeStrategy())->computeChange($due, $available));

        $change = (new BacktrackingChangeStrategy())->computeChange($due, $available);

        self::assertNotNull($change);
        self::assertTrue($change->total()->equals($due));
    }

    /**
     * @return iterable<string, array{ChangeStrategy, Money, CoinSet}>
     */
    public static function soundnessCases(): iterable
    {
        $strategies = [
            'backtracking' => new BacktrackingChangeStrategy(),
            'greedy' => new GreedyChangeStrategy(),
        ];

        foreach ($strategies as $label => $strategy) {
            yield "{$label}: zero change is due" => [
                $strategy, Money::zero(), CoinSet::empty()->add(Coin::TEN, 2),
            ];
            yield "{$label}: change from mixed coins" => [
                $strategy, Money::fromCents(45), CoinSet::empty()->add(Coin::TWENTY_FIVE, 4)->add(Coin::TEN, 4),
            ];
            yield "{$label}: greedy-stranding stock" => [
                $strategy, Money::fromCents(30), CoinSet::empty()->add(Coin::TWENTY_FIVE, 1)->add(Coin::TEN, 3),
            ];
            yield "{$label}: a 100c coin is on hand" => [
                $strategy, Money::fromCents(50), CoinSet::empty()->add(Coin::HUNDRED, 1)->add(Coin::TWENTY_FIVE, 2),
            ];
            yield "{$label}: no combination exists" => [
                $strategy, Money::fromCents(5), CoinSet::empty()->add(Coin::TEN, 5),
            ];
        }
    }
}
