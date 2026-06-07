<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Property;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VendingMachine\Domain\Catalog\Catalog;
use VendingMachine\Domain\Catalog\Product;
use VendingMachine\Domain\Change\BacktrackingChangeStrategy;
use VendingMachine\Domain\Exception\DomainException;
use VendingMachine\Domain\Inventory\ItemInventory;
use VendingMachine\Domain\Machine\OperationalMode;
use VendingMachine\Domain\Machine\VendingMachine;
use VendingMachine\Domain\Money\Coin;
use VendingMachine\Domain\Money\CoinSet;
use VendingMachine\Domain\Money\Money;
use VendingMachine\Tests\Support\Prng;

/**
 * Money is conserved across random valid command sequences.
 *
 * A seeded, reproducible fuzz — not generator-based property testing: for each seed a deterministic
 * PRNG drives a few hundred mode-legal commands, and after every step the test asserts the ledger
 * identity
 *
 *     bank + session  ==  baseline + inserted - returned - dispensed
 *
 * The right-hand side is known entirely from what the test fed in and got back (coins inserted, coins
 * returned, change dispensed), never read from the machine — so a mismatch means the machine created
 * or lost money. The single property ties together the late dump into the bank on a sale, the
 * fail-closed (non-destructive) rejection, and RETURN-COIN handing back exactly the session.
 *
 * SERVICE is the deliberate exception (decision #14): setAvailableChange injects external value with
 * set semantics, so conservation is not expected to span it — the test re-baselines to the declared
 * reserve instead of pretending it holds. Accounting for the service delta instead would force the
 * test to read the bank, predicting the state from the very state under test (a tautology).
 *
 * The generator only ever issues commands legal for the current mode, so a thrown IllegalState would
 * fail the test: the fuzz therefore also asserts the mode guards never fire under legal use. Sales may
 * still be refused for ordinary reasons (insufficient funds, out of stock, no change, unknown
 * product); those recoverable DomainExceptions move nothing and are tolerated.
 *
 * This is a property over already-built behaviour, so it is expected to pass on first run — a red here
 * would be a genuine money bug surfaced by the fuzz, which is the point.
 */
final class MoneyConservationFuzzTest extends TestCase
{
    private const int STEPS = 400;

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= 16; $seed++) {
            yield "seed {$seed}" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function test_money_is_conserved_across_random_valid_command_sequences(int $seed): void
    {
        $prng = new Prng($seed);
        $machine = VendingMachine::operational(self::catalog());
        $strategy = new BacktrackingChangeStrategy();

        // The ledger the test maintains independently of the machine. A fresh machine holds nothing.
        $baseline = 0;
        $inserted = 0;
        $returned = 0;
        $dispensed = 0;

        for ($step = 0; $step < self::STEPS; $step++) {
            if ($machine->mode() === OperationalMode::Operational) {
                $choice = $prng->nextBelow(4);

                if ($choice === 0) {
                    $inserted += $this->insertRandomCoin($prng, $machine);
                } elseif ($choice === 1) {
                    $returned += $machine->returnCoins()->total()->cents;
                } elseif ($choice === 2) {
                    $code = self::code($prng->nextBelow(4));

                    try {
                        $dispensed += $machine->selectItem($code, $strategy)->change->total()->cents;
                    } catch (DomainException) {
                        // Refused sale (no funds / no stock / no change / unknown product): nothing moved.
                    }
                } elseif ($machine->insertedAmount()->equals(Money::zero())) {
                    $machine->enterService();
                } else {
                    // Cannot enter Service with coins in the tray; insert instead, so every step is a
                    // legal command and the generator never provokes an IllegalState.
                    $inserted += $this->insertRandomCoin($prng, $machine);
                }
            } else {
                $choice = $prng->nextBelow(3);

                if ($choice === 0) {
                    $reserve = $this->randomReserve($prng);
                    $machine->setAvailableChange($reserve);
                    // SERVICE injects external value (decision #14): re-baseline rather than conserve.
                    $baseline = $reserve->total()->cents;
                    $inserted = 0;
                    $returned = 0;
                    $dispensed = 0;
                } elseif ($choice === 1) {
                    $machine->restockItems($this->randomStock($prng));
                } else {
                    $machine->leaveService();
                }
            }

            $insideMachine = $machine->availableChange()->total()->cents + $machine->insertedAmount()->cents;
            self::assertSame(
                $baseline + $inserted - $returned - $dispensed,
                $insideMachine,
                "money not conserved at seed {$seed}, step {$step}",
            );
        }
    }

    private function insertRandomCoin(Prng $prng, VendingMachine $machine): int
    {
        $coin = self::inputCoin($prng->nextBelow(4));
        $machine->insertCoin($coin);

        return $coin->valueInCents();
    }

    private function randomReserve(Prng $prng): CoinSet
    {
        $reserve = CoinSet::empty();

        for ($i = 0; $i < 3; $i++) {
            $reserve = $reserve->add(self::changeCoin($i), $prng->nextBelow(6));
        }

        return $reserve;
    }

    private function randomStock(Prng $prng): ItemInventory
    {
        return ItemInventory::fromQuantities([
            'WATER' => $prng->nextBelow(4),
            'SODA' => $prng->nextBelow(4),
            'JUICE' => $prng->nextBelow(4),
        ]);
    }

    private static function inputCoin(int $i): Coin
    {
        // Input denominations a customer can insert (the 1.00 is accepted to pay, never given as change).
        return match ($i) {
            0 => Coin::FIVE,
            1 => Coin::TEN,
            2 => Coin::TWENTY_FIVE,
            default => Coin::HUNDRED,
        };
    }

    private static function changeCoin(int $i): Coin
    {
        // Dispensable denominations the technician can stock as change.
        return match ($i) {
            0 => Coin::FIVE,
            1 => Coin::TEN,
            default => Coin::TWENTY_FIVE,
        };
    }

    private static function code(int $i): string
    {
        // Three real products plus one unknown code, to exercise the UnknownProduct rejection path.
        return match ($i) {
            0 => 'WATER',
            1 => 'SODA',
            2 => 'JUICE',
            default => 'COLA',
        };
    }

    private static function catalog(): Catalog
    {
        return Catalog::of(
            Product::create('WATER', Money::fromCents(65)),
            Product::create('SODA', Money::fromCents(150)),
            Product::create('JUICE', Money::fromCents(100)),
        );
    }
}
