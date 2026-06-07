# Decision Log

Architecture and design decisions, recorded as they are taken so the rationale survives review.
Each entry states the **context**, the **decision**, the **alternatives considered**, and the
**consequences**. Money is always handled in integer cents.

---

## 1. Containerized PHP 8.3 toolchain (Docker)

- **Context:** The project must be reproducible on any reviewer's machine and should not depend on a
  local PHP/Composer installation.
- **Decision:** Ship a `php:8.3-cli` Docker image (Composer included, non-root user matching the host
  UID/GID) plus a `docker-compose.yml` and a `Makefile` that wraps the Composer scripts. The whole dev
  loop is `make build && make install && make test`.
- **Alternatives:** A native PHP/Composer install (less reproducible, more setup for a reviewer).
- **Consequences:** Reproducibility comes from two complementary pins — Docker pins the *runtime*
  (PHP version + extensions), `composer.lock` pins the *dependencies*. The image tag could be pinned by
  immutable digest for an even stronger guarantee (documented in the README).

## 2. PHPStan at `level max`, no baseline

- **Context:** Greenfield codebase with no legacy debt.
- **Decision:** Run PHPStan at `level max` with `phpstan-strict-rules`, and **no baseline**.
- **Alternatives:** A lower numeric level, or a baseline to defer existing findings.
- **Consequences:** A baseline only hides problems; with no legacy debt there is nothing to defer, so
  the bar is held from line one. `max` is a *moving alias* for the highest available level, so a PHPStan
  upgrade could introduce new findings without a code change — this is neutralised by pinning the exact
  PHPStan version in the committed `composer.lock`.

## 3. Money as integer cents, never floating point

- **Context:** Currency arithmetic with binary floats is lossy (e.g. `0.1 + 0.2 != 0.3`).
- **Decision:** Model money as integer cents inside immutable value objects (`Money`, `Coin`,
  `CoinSet`). All arithmetic is exact integer arithmetic.
- **Consequences:** The only place a float could enter is the CLI boundary, when decimal text is parsed
  into cents. That parsing is string-based (never `floatval`) and guarded by a round-trip test. The
  domain itself never sees a float.

## 4. Coins modeled as an immutable multiset (`CoinSet`)

- **Context:** The machine must return *exactly the coins the customer inserted* on RETURN-COIN
  (spec example: `0.10, 0.10 → 0.10, 0.10`).
- **Decision:** Represent any collection of coins as a `CoinSet` — an immutable multiset of
  denomination → count — rather than a single integer total.
- **Alternatives:** Track only a total in cents.
- **Consequences:** A bare total loses *which* coins are present, so RETURN-COIN would have to recompose
  an amount from the bank (possibly returning different coins, or failing). Keeping the multiset makes
  RETURN-COIN a total operation that returns the inserted coins, and gives the change algorithm the exact
  available denominations.

## 5. No separate `CoinInventory` — `CoinSet` is the coin inventory

- **Context:** Both the bank/change drawer and the session retention tray are collections of coins.
- **Decision:** Use `CoinSet` directly for both. Do **not** introduce a `CoinInventory` class.
- **Alternatives:** A dedicated `CoinInventory` type (as initially sketched).
- **Consequences:** A `CoinInventory` would duplicate `CoinSet`'s behaviour (add/remove/count/total)
  with no added invariant — that is over-engineering. `ItemInventory` *is* kept as its own type because
  "code → stock" has no prior value object and its own domain language (dispense, out-of-stock).

## 6. Layer-boundary enforcement: PHPat, not deptrac

- **Context:** The design relies on layered boundaries (Domain must not depend on Infrastructure, etc.)
  and these should be enforced automatically, not by review.
- **Decision:** Use **PHPat**, a maintained PHPStan extension that expresses architecture rules inside
  the existing static-analysis pass, so `make stan` also enforces the boundaries.
- **Alternatives:** **deptrac** — the originally planned tool, but it was **archived in February 2025**.
  Shipping an unmaintained tool in a current submission is undesirable, and deptrac runs as a separate
  binary with its own dependency graph.
- **Consequences:** One analysis pass, one tool, no second dependency graph. Rules are wired once the
  layers exist (the domain currently has no Infrastructure layer to violate).

## 7. Prices are positive multiples of 5 cents, validated fail-fast

- **Context:** Change must always be representable with the change denominations {5, 10, 25}.
- **Decision:** A `Product` rejects, at construction, any price that is not a positive multiple of 5.
- **Consequences:** Every coin is a multiple of 5, so any inserted total is too; requiring the price to
  be one makes the change due always a multiple of 5 — always representable. The case "change amount is
  not representable arithmetically" becomes structurally impossible, so the change algorithm can only
  ever fail for one reason: insufficient coin stock (feasibility), never arithmetic.
- **Known coupling (to refine in step 6):** the divisor `5` is not arbitrary — it is the gcd of the
  change denominations {5,10,25} (which here also equals the smallest change coin, so multiples of 5 are
  exactly the representable amounts; this is a property of the set, not a general law). Hardcoding `5`
  in `Product` couples the price rule to the current coin set. The extensible design derives this
  granularity from the configured change denominations and validates it where prices meet denominations
  — the machine configuration — not as a constant inside `Product`. For example, adding a 2c coin as
  *change* makes the gcd 1 (prices then unconstrained); adding 2c as *input only* would break
  representability with {5,10,25}, a deeper feasibility concern than a `Product` check. Divisibility is
  only the necessary precondition; the backtracking change algorithm is the actual arbiter of
  feasibility. To be addressed when machine configuration (`MachineConfig`) is introduced.

## 8. Typed errors in two categories

- **Context:** Some failures are programming/configuration mistakes; others are expected runtime
  conditions caused by user input or machine state.
- **Decision:** Broken invariants (a non-multiple-of-5 price, an empty catalog, duplicate codes) raise
  `\InvalidArgumentException` (a `LogicException`) and are allowed to bubble — they indicate a bug.
  Expected runtime/user conditions (selecting an unknown product, selecting an out-of-stock product)
  raise typed **domain exceptions** (`UnknownProduct`, `OutOfStock`, both `RuntimeException`s) that will
  be caught at the CLI boundary and mapped to user-facing messages.
- **Consequences:** A single, predictable mapping point at the boundary. A common `DomainException` base
  is deferred until several domain exceptions exist and catching them by category earns its keep (YAGNI).

## 9. Fail closed on money operations

- **Context:** This is real money; an incorrect result must never silently shortchange a customer.
- **Decision:** Money operations refuse rather than approximate — `Money` cannot go negative,
  `CoinSet`/`ItemInventory` reject removing more than is present, and (upcoming) the change algorithm
  returns "no change possible" rather than an approximate amount.
- **Consequences:** On any doubt the system declines the operation and leaves state consistent, never
  guessing with the customer's money.

## 10. Change is returned as `?CoinSet`, not wrapped in a `Change` value object

- **Context:** The change computation has three distinct outcomes: no change due (exact payment), the
  coins to dispense, or no possible combination.
- **Decision:** The strategy returns `?CoinSet`. `null` means no combination of available, dispensable
  coins sums to the amount (impossible). An **empty** `CoinSet` means no change is due (exact payment —
  a valid sale). A populated `CoinSet` is the breakdown to dispense.
- **Alternatives:** A dedicated `Change` value object wrapping a `CoinSet`.
- **Consequences:** Distinguishing "zero change" (empty) from "impossible" (`null`) is essential —
  collapsing both into `null` would make the aggregate reject a valid exact-payment sale. A `Change`
  type would duplicate `CoinSet`'s behaviour (`total`/`count`/`isEmpty`) with no new behaviour — the
  same reasoning that ruled out `CoinInventory` (#5). The one invariant `Change` could add — "never
  contains the 100c coin" — is instead guaranteed at the source (the strategy only draws from coins that
  are dispensable as change) and verified by tests, so it need not be reified into a type. The aggregate
  consumes the `CoinSet` directly when it deducts change from the bank, with no unwrapping.

## 11. Change-making by backtracking; the strategy returns `null`, the aggregate fails closed

- **Context:** With a finite coin stock a greedy pick can fail to find change that exists (e.g. 30c from
  `[25×1, 10×3]`: greedy takes the 25, cannot make the remaining 5, and gives up — yet `10+10+10` works).
- **Decision:** Compute change by exhaustive backtracking over the dispensable denominations, highest
  first, bounded per level by `min(stock, remaining / value)`. `{5,10,25}` is a canonical system, so the
  failure comes *only* from finite stock, never the denominations. The strategy returns `null` when no
  combination exists; the **aggregate** (step 6) translates that `null` into the `CannotDispenseChange`
  domain exception and fails the sale closed. The strategy itself never throws: "no combination" is a
  normal, expected result of a pure computation, not an exceptional condition — and deciding to refuse
  the sale is a business decision that belongs to the aggregate, not the calculator.
- **Alternatives:** Greedy (kept only as a contrast fixture in the tests, never wired in production);
  dynamic programming (bounded coin-change), which yields the minimum-coin solution naturally.
- **Consequences:** Backtracking returns the concrete coin breakdown directly (what the sale must
  dispense) and the search space is trivial here (≤3 change denominations, small amounts), so its
  worst-case exponential nature is irrelevant; DP would be the migration path if minimising coins ever
  became a requirement. A contract test runs over both implementations and checks **soundness** (any
  returned `CoinSet` sums exactly and uses only available, dispensable coins); the 30c case proves
  backtracking's **completeness**, which greedy lacks. Returning `?CoinSet` (not a `Change` VO) is #10.

## 12. Operational mode as a two-state machine (enum + mode guards)

- **Context:** The machine alternates between serving customers and being opened by a technician.
  These two situations are mutually exclusive and event-driven — the technician opens the machine to
  service it and closes it afterwards.
- **Decision:** Model the mode as an `OperationalMode` enum (`Operational` / `Service`) held by the
  aggregate, with explicit transitions `enterService()` / `leaveService()` and a single private
  `guardMode()` through which every operation declares the mode it requires. Customer actions
  (`insertCoin`, `returnCoins`, the upcoming `selectItem`) require Operational; the service operations
  require Service. A call from the wrong mode raises `IllegalState` (see #13).
- **Alternatives:** The GoF State pattern, one class per state.
- **Consequences:** At two modes, an enum plus guards is the right altitude. A State-class hierarchy
  would tempt reifying *derived* conditions ("exact change only", "sold out") as states, forcing an
  artificial recomputed transition after every operation to keep them in sync with the inventory that
  is their real source of truth. Those conditions are **not** modes — they are pure functions of the
  current inventory and will land as policies, not state. The rule applied: reify as state only what
  has an event-triggered transition with real mutually-exclusive memory; derive everything else. The
  design graduates to State classes only if the modes multiply.

## 13. `SessionNotEmpty` is recoverable; a mode violation is a bug (the two-category split, realized)

- **Context:** #8 established two error categories in principle. Slice 2 is the first place both meet
  on the aggregate, and one case is genuinely ambiguous: what should happen when a technician opens
  the machine for service while a customer has left coins in the retention tray?
- **Decision:** That case raises `SessionNotEmpty` — a recoverable `DomainException`; the documented
  recovery is to run RETURN-COIN and retry `enterService()`. It is **not** an `IllegalState`. By
  contrast, invoking a customer action in Service mode (or a service action in Operational) raises
  `IllegalState`, a `LogicException` that bubbles unmapped. This realizes the `DomainException` base
  deferred in #8: with `OutOfStock`, `UnknownProduct` and now `SessionNotEmpty`, a third domain
  exception arrives, so catching the category at the boundary finally earns its keep.
- **Alternatives:** (a) Treat session-not-empty as an illegal state — rejected: a customer walking
  away mid-purchase is a real physical situation, not a programming error, so failing it as a bug
  would be wrong. (b) Have `enterService()` auto-return the coins — rejected: that overloads the
  transition with a refund side effect, when RETURN-COIN already does exactly that, so the recovery
  composes cleanly from an existing operation and the transition stays single-purpose.
- **Consequences:** The line between "recoverable, map to a user message" and "bug, let it bubble" is
  drawn by a single test: *could a correct driver ever trigger it?* `SessionNotEmpty` is reachable by
  ordinary use; a mode violation is not. `IllegalState` (`LogicException`) and the `DomainException`
  hierarchy (`RuntimeException`) live on disjoint branches, so the two never share a catch. The split
  is verified behaviourally — the tests catch a refusal via its base class — rather than by reflecting
  on the class hierarchy (which would only re-test PHP's `extends` keyword, and PHPStan rightly flags
  such assertions as statically certain). Atomicity is free: every guard throws before the lone mode
  assignment, so a refused transition leaves the aggregate untouched.

## 14. SERVICE uses set semantics, and is the deliberate break in money conservation

- **Context:** SERVICE has two responsibilities — *"set the available change and how many items we
  have"*. Each could be modelled as a replacement (declare the absolute inventory) or an increment
  (top up what is already there).
- **Decision:** Both `setAvailableChange()` and `restockItems()` use **set** semantics — they replace
  the change reserve and the product stock with the inventory the technician declares — matching the
  spec's verbatim "set". A fresh machine starts empty and is filled by the technician; both operations
  are gated to Service mode through the same `guardMode()` as every other operation.
- **Alternatives:** Incremental top-up (`add`). Rejected as the default because the spec says "set",
  and because a declared absolute post-state is unambiguous and auditable.
- **Consequences:** SERVICE is the **one point where money conservation deliberately does not hold**:
  value enters or leaves the system by the technician's decision, not by a sale. Modelling it as `set`
  makes that explicit — the post-state is fully declared, not reconstructed from a delta — which is the
  right contract for the boundary the conservation property test (a later slice) treats as a reset of
  its baseline. If the business later wanted "add N coins to the existing reserve", that would be a
  separate, differently-named operation (`AddChange`), never a reinterpretation of `set`.

---

## 15. The sale is a transaction the aggregate owns (`selectItem`)

- **Context:** Selling is the core operation and the place a domain most easily turns anemic — the
  legality of a sale (mode, product, stock, funds, change) and the change computation could all leak
  into a service or the CLI. It also moves money: the customer's coins must join the bank, the change
  must leave it and the item stock must drop, all together or not at all.
- **Decision:** `VendingMachine::selectItem(string $code, ChangeStrategy $strategy): VendingResult`
  owns the whole transaction. It validates cheapest-first — mode guard, product exists
  (`UnknownProduct`), in stock (`OutOfStock`), funds cover the price (`InsufficientFunds`) — and only
  then runs the expensive change search over a **tentative bank** (`bank.plus(sessionCoins)`). If the
  strategy returns `null` the sale fails closed with `CannotDispenseChange`. The commit computes the
  new bank (`tentative.minus(change)`), the dispensed stock and the emptied session into locals, then
  assigns the fields last, and returns a `VendingResult` (product + change `CoinSet`). This realizes
  the previously-pending "transactional sale via a tentative inventory".
- **Alternatives:** (a) A `SaleService` orchestrating reads and writes against an aggregate of
  getters/setters — rejected as the textbook anemic domain; the proof we avoid it is that deleting any
  service leaves the invariants intact, because they live in `selectItem`. (b) Mutating the real
  bank/stock as we go and compensating on failure — rejected; immutable value objects make a tentative
  copy free, so "assign last" yields all-or-nothing with no rollback logic.
- **Consequences:**
  - **Atomicity is free.** Every `throw` precedes the first assignment, and assignments cannot fail, so
    a rejected sale (no change, no funds, no stock) leaves session, stock and bank exactly as they were
    — fail-closed and non-destructive, so the customer retries or takes the coins back. Pinned by a test
    that drives `CannotDispenseChange` and asserts all three are untouched.
  - **Per-sale money conservation.** Session coins join the bank only at commit (the late dump), so the
    bank grows by exactly the price (`bank_after = bank_before + price`). This local postcondition
    complements the global conservation property (a later slice), which alone would not catch a
    mis-distributed change.
  - **`Catalog` vs `ChangeStrategy` live in different places, by design.** The catalog is the machine's
    identity/config — persisted, varies per machine — so it is a `readonly` **constructor** dependency.
    The strategy is stateless behaviour used only by the sale and is never persisted, so it is injected
    **per method call**, keeping the aggregate pure data for the future repository. PHPStan `max`'s
    read-when-used rule forced the catalog into the constructor in the same change that first reads it,
    so the config and the behaviour that needs it landed together.
  - `InsufficientFunds` and `CannotDispenseChange` join the recoverable `DomainException` category;
    `VendingResult` is a public-constructor value object (no invariant to protect, so no factory).

---

## 16. Derived conditions stay derived — no sold-out read-model, no exact-change policy

- **Context:** #12 set the rule that only event-triggered, mutually-exclusive memory is reified as
  state; everything derivable from the inventory stays derived. Two such derived conditions were
  planned as policies for this last aggregate slice: a machine-wide "sold out" and an "exact change
  only" pre-warning.
- **Decision:** Ship neither as code. "Sold out" **collapses into the per-product `OutOfStock`** that
  `selectItem` already raises (#15): a machine-wide `isSoldOut()` would add no refusal that does not
  already exist, only a display query with no consumer (the CLI is a later step). "Exact change only"
  is **cut entirely**: it was only a pre-emptive UX warning, and its predicate could never be defined
  crisply — "can I make change for the worst plausible purchase?" is the expensive combinatorial
  question we declined to answer.
- **Alternatives:** (a) A thin `isSoldOut()` read-model to anchor #12 with code — rejected as YAGNI:
  when the CLI needs a "sold out" banner it is a one-liner over the catalog and `stockOf`, and #12 is
  already demonstrated by the *absence* of state classes, not by a token query. (b) An
  `ExactChangePolicy` (a declared conservative heuristic over the coin stock) — rejected because the
  fail-closed sale (`CannotDispenseChange` at commit, #11/#15) already guarantees the machine never
  shortchanges, so the warning adds zero correctness and only an interview trap ("what exactly is the
  predicate?").
- **Consequences:** The derived-conditions chapter closes with no new types. The proof that derived
  conditions are not modes is structural — there are no State classes and no policy objects — exactly
  as #12 argued. Correctness when change is scarce lives entirely in the fail-closed sale, never in a
  pre-warning.

## 17. Money conservation as a seeded invariant fuzz

- **Context:** The strongest aggregate invariant is that money is neither created nor destroyed by
  customer operations: `bank + session` changes only by what the customer inserts, takes back, or
  receives as change. A single test over random command sequences can tie RETURN-COIN, the
  transactional sale and the SERVICE boundary into one property.
- **Decision:** A seeded, reproducible fuzz (`MoneyConservationFuzzTest`). For each seed a small
  deterministic PRNG (`Prng`, a linear congruential generator in `tests/Support`) drives a few hundred
  mode-legal commands; after every step the test asserts
  `bank + session == baseline + inserted - returned - dispensed`. The right-hand side is an
  **independent ledger** built only from what the test fed in and got back (coins inserted, coins
  returned, change dispensed) — never read from the machine. SERVICE **re-baselines** it (#14):
  `setAvailableChange` declares an absolute reserve, so the baseline resets to that reserve and the
  accumulators zero.
- **Alternatives:** (a) Generator-based property testing (Eris/QuickCheck) with shrinking — rejected
  as machinery we would have to defend for a property this direct; the test is named honestly as a
  focused fuzz, not "property-based testing". (b) `mt_rand` for the sequence — rejected because its
  stream depends on the engine; a hand-rolled LCG makes "seeded/reproducible" platform-independent
  (it rides on the pinned 64-bit runtime, #1). (c) Computing `dispensed` from the bank delta instead
  of the `VendingResult` — rejected because it makes the ledger and the machine the same source,
  collapsing the assertion to a tautology that can never fail.
- **Consequences:** The independent ledger is what gives the test teeth: it compares the machine's
  internal state against an external account of what physically crossed the boundary, so it catches
  money lost or created even when the machine's own books are internally consistent. It is a
  characterization test over already-built behaviour, so it passes on first run — a red would surface
  a genuine money bug. Because the generator only issues mode-legal commands, a thrown `IllegalState`
  fails the test, so the fuzz also pins that the mode guards never fire under legal use; ordinary
  refusals (`InsufficientFunds`, `OutOfStock`, `CannotDispenseChange`, `UnknownProduct`) move nothing
  and are tolerated. What it does *not* catch — change with a correct total but the wrong coins — is
  covered by the strategy soundness contract test (#11) and the per-sale postcondition (#15).

## 18. The aggregate's persistence is a driven port with an in-memory adapter

- **Context:** The aggregate has a lifecycle — it must be loaded before an operation and saved after —
  but the design deliberately ships no real database (that would be infrastructure noise without
  demonstrating new design). Something must still own load/save, and a test adapter must stand in for
  the real thing faithfully.
- **Decision:** A driven (output) port `VendingMachineRepository` owned by the domain exposes
  `load(): VendingMachine` and `save(VendingMachine): void`, with an `InMemoryVendingMachineRepository`
  adapter as the first Infrastructure piece. There is a single physical cabinet, so the port models one
  machine and `load()` is **total** (no find-by-id, no nullable result — the machine always exists); the
  in-memory adapter is **seeded at construction** because, unlike a database, it has no external store to
  read the initial state from. Both `load()` and `save()` **clone** the aggregate.
- **Alternatives:** (a) A nullable `load(): ?VendingMachine` with the application bootstrapping the
  machine — rejected: a physical cabinet always exists, and a total load keeps null-handling out of every
  caller. (b) A by-reference store (`return $this->machine`) — rejected: see consequences. (c) A
  `RepositoryContractTest` — rejected: with a single adapter it only re-tests that adapter, a tautology;
  a contract test earns its keep only with two or more implementations (contrast the `ChangeStrategy`
  contract test in #11, justified precisely because Greedy and Backtracking must agree). (d) A second
  real adapter (Sqlite/File) "to prove the port" — rejected as infrastructure noise; the port plus this
  entry already document that a new adapter is a new class with zero domain change.
- **Consequences:** Cloning gives **snapshot semantics matching a real database**: each `load()` yields an
  independent copy and each `save()` captures the state at the moment of the call. This matters because the
  aggregate is **mutable** — a by-reference store would let a caller mutate persisted state without saving,
  and would make tests pass that a real database would fail (a leaky fake). A **shallow** clone suffices:
  every field of the aggregate is an immutable value object or enum, so the copy shares no mutable state —
  the aggregate only ever changes by reassigning a field to a new value object. This is the same property
  that made the sale's atomicity free in #15 (immutable VOs behind a mutable entity), reused here for
  persistence isolation. Swapping in a Doctrine/PDO/Redis adapter is a new class behind the port, no domain
  change.

## 19. Layer boundaries are enforced now that they exist (PHPat wired)

- **Context:** #6 chose PHPat over the archived deptrac but deferred wiring the rules until there were
  layers to enforce. Step 7 (the repository adapter) creates the first `Domain -> Infrastructure`
  boundary, so the trigger is met.
- **Decision:** Register a single PHPat rule — **the domain depends on nothing outside itself**, neither
  `Application` nor `Infrastructure` — as a `phpat.test` service so it runs inside the existing
  `composer stan` pass. It is expressed with the (populated) `Domain` namespace as the subject and the two
  outer namespaces as targets.
- **Alternatives:** A separate deptrac run (an archived tool and a second dependency graph — the rejection
  from #6); waiting for more layers before wiring anything (rejected: the core boundary already exists and
  is the one most worth protecting).
- **Consequences:** Enforcement lives in the single static-analysis gate, no second binary. The
  `Application` target matches no classes yet, so it enforces **vacuously** today and starts biting the
  moment step 8 introduces that layer (which will also add the `Application -> Infrastructure` rule). The
  rule was confirmed to **bite** by temporarily pointing a domain class at the in-memory adapter and
  watching `composer stan` fail with the boundary message — then removing the probe. This realizes #6.

## 20. The application layer is a driving port with an anemic orchestrating service

- **Context:** The aggregate needs a thin layer that loads it, delegates a use case and saves it, and
  a boundary the delivery adapter can call. This is exactly where a domain most easily turns anemic —
  if the legality of an operation or any state-shuffling leaked into a service, the aggregate would
  become a bag of getters/setters.
- **Decision:** A driving (input) port `MachineDriver` exposes the use cases (insertCoin, returnCoins,
  selectItem, the four service operations), implemented by `VendingMachineService`. Every method is
  `load -> delegate to the aggregate -> save`, with no business logic of its own. The `ChangeStrategy`
  is a constructor dependency of the service and is handed to the aggregate inside `selectItem`, so it
  stays out of the port's contract and out of the aggregate's persistable state. The command objects
  sketched in the original plan are **cut**.
- **Alternatives:** (a) Hexagonal-lite with no input interface — the delivery adapter depends directly
  on the concrete service. A defensible YAGNI, but the driving port is the seam that realizes the
  "CLI today, HTTP tomorrow" the brief explicitly invites, it is symmetric to the repository port, and
  it is a single interface justified in one sentence. (b) Command objects plus a command bus — rejected:
  there is no bus, queue or command serialization here, so they would be a layer with no behaviour.
- **Consequences:** The service is provably anemic — delete it and every invariant survives in the
  aggregate (the test for the anemic-domain smell). A failing operation throws from the aggregate before
  `save()` is reached, so a refused command persists nothing with no try/catch, and the exception bubbles
  to the delivery layer's error mapper. Keeping the input port while cutting command objects is the same
  discipline as #18 (keep the repository port, cut the redundant second adapter and the tautological
  contract test): keep the boundary, cut redundant implementations. The `Application -> Infrastructure`
  PHPat rule (#19) now has a real subject and is enforced.

## 21. The CLI coin parser is float-blind, and its input error lives in the delivery layer

- **Context:** Money is integer cents throughout the application; the one place a float could ever enter
  is parsing decimal coin text at the CLI boundary (e.g. "0.25").
- **Decision:** `CoinParser` converts a token to a `Coin` using only string matching and integer
  arithmetic — a regex for a whole number with one or two optional decimals, right-padding the fraction
  to cents — **never** `floatval`. The resulting cents must be a real denomination; a malformed token or
  a non-denomination raises `InvalidCoin`. `InvalidCoin` lives in `Infrastructure/Cli`, not the domain:
  rejecting input text is an adapter concern, and the domain only ever sees a valid `Coin`. The parser
  is strict about surrounding whitespace — splitting and trimming a command line is the caller's job.
- **Alternatives:** (a) `floatval`-based parsing — rejected as lossy: `0.29 * 100 = 28.999...`, which an
  int cast turns into 28 cents. (b) Placing `InvalidCoin` in the domain as a `DomainException` — it is a
  recoverable, user-facing error like the domain's category, but it is never raised by the domain, only
  by the adapter, so it lives in the delivery layer; it still extends `RuntimeException` so the error
  mapper handles it in the same recoverable channel.
- **Consequences:** The domain is float-free by construction, and the conversion is a single, tested
  chokepoint (a decimal-to-cents table plus the rejection set). The CLI error mapper (a later slice) will
  catch both the domain's recoverable exceptions and `InvalidCoin`, mapping them to messages and stable
  exit codes, while programming errors (`IllegalState`) keep bubbling unmapped.

## 22. The CLI output formatter is float-blind too — the mirror of the parser

- **Context:** Money is integer cents throughout the application. #21 sealed the input door (decimal text
  -> cents). Rendering cents back to the decimal text the CLI prints is the **output door**, and the
  second and last place a float could ever enter — the mirror of the parser.
- **Decision:** `OutputFormatter::formatCoin` renders a `Coin` as a canonical decimal string using only
  integer arithmetic: `sprintf('%d.%02d', intdiv($cents, 100), $cents % 100)`. It **never** divides by
  100 into a float (`number_format($cents / 100, 2)`). It takes a `Coin` — not a `Money` or a raw int —
  so it is the symmetric inverse of the parser (`string -> Coin` / `Coin -> string`), is type-safe, and
  composes with set rendering (iterate `Coin::cases()` x `count()`). The parser is liberal in what it
  accepts ("1", "1.00", "0.1", "0.10"); the formatter emits one canonical form per amount — always two
  decimal places — which always round-trips back through the parser.
- **Alternatives:** (a) `number_format($cents / 100, 2)` — rejected. Unlike the input door, the bug here
  is **latent, not visible**: `number_format` rounds (it does not truncate like the int cast in #21), so
  for the current denominations it yields the same string today. But it manufactures an imprecise float
  (`0.05 -> 0.05000...277`) and relies on the rounding step to scrub it; the "it rounds correctly"
  guarantee is fragile under new denominations, other currencies, or larger amounts; and it puts an
  asterisk on the "money never touches a float anywhere" invariant. The integer form is exact by
  construction — there is nothing to round and nothing to mask. (b) `formatAmount(int $cents)` or
  `format(Money)` — more general, but the CLI only ever renders coins and product codes, never a free
  amount, so `Coin` is the precise, YAGNI-honest input and keeps the parser/formatter pair symmetric.
- **Consequences:** Both boundary doors (input #21, output #22) are now float-blind, so the integer-cents
  invariant has zero exceptions to defend. The output-side bug is closed before it can hide — a silent
  bug masked by a lucky rounding is worse than a visible one, because it surfaces long after the cause is
  forgotten. Line assembly (a `CoinSet` -> "0.25, 0.10", a `VendingResult` -> "WATER, 0.25, 0.10") is a
  later slice that composes `formatCoin`; `CoinSet`'s private contents are read via `count()` per
  `Coin::cases()`, so its encapsulation holds.

## 23. Formatting a CoinSet lives in the adapter, not on the value object

- **Context:** The CLI prints a sale's change and the coins returned by RETURN-COIN as a comma-separated
  decimal list, highest denomination first ("0.25, 0.10"). `CoinSet` keeps its contents private and
  exposes no iterator — only `count()`, `total()`, `isEmpty()`.
- **Decision:** `OutputFormatter::formatCoins` walks the fixed denomination set
  (`array_reverse(Coin::cases())`, highest first) and asks `count()` per denomination, repeating
  `formatCoin` that many times and joining with ", "; an empty set renders to "". Ordering and joining
  live in the adapter.
- **Alternatives:** (a) add `CoinSet::toArray()` / an iterator and have the formatter consume it —
  rejected: it puts a presentation-driven method on a domain value object and leaks its internal
  representation. (b) put `formatCoins` on `CoinSet` itself — rejected: formatting is **presentation**,
  not domain behaviour; the domain must not know how coins are displayed (an HTTP adapter would render
  them differently — JSON, another order). The contrast with #15 (slice 4a) is the point: `plus()` and
  `minus()` *did* go on `CoinSet` because they are **domain arithmetic** the aggregate composes. The
  deciding axis is the **category** of the logic (domain behaviour vs presentation), not "needs private
  access" — both share that obstacle, and it resolves oppositely: a domain operation becomes a method on
  the VO; a presentation concern stays in the adapter and uses the public `count()` query. The litmus
  test: an alternate (HTTP) adapter would reuse `plus`/`minus` but not `formatCoins`. (c) `usort` the
  denominations by `valueInCents()` descending instead of `array_reverse` — more robust to a future enum
  reorder, but heavier for a fixed four-coin enum; `array_reverse` is fine because the "ordered highest
  first" test pins the order and fails loudly if the enum is ever reordered.
- **Consequences:** `CoinSet`'s surface stays minimal (the "don't add a method that earns nothing"
  discipline), the display order is owned by the view rather than the unordered multiset, and the domain
  has no idea the CLI exists. `formatCoins` composes `formatCoin`, so the float-blindness (#22) is
  inherited for free — this method is pure assembly and touches no float itself.

## 24. Rendering a sale: the empty-change branch reflects the domain's exact-payment state

- **Context:** `selectItem` returns a `VendingResult` (product + change `CoinSet`). The CLI prints the
  product code followed by its change ("WATER, 0.25, 0.10") or, when payment was exact, just the code
  ("SODA").
- **Decision:** `OutputFormatter::formatSale` returns the bare product code when `change->isEmpty()`,
  otherwise `code . ", " . formatCoins(change)`. The product code is passed through verbatim — codes are
  spec tokens (WATER/SODA), not money, so no formatting applies to them.
- **Alternatives:** the unconditional one-liner `code . ", " . formatCoins(change)` — rejected:
  `formatCoins` of an empty set is "", so it prints "SODA, " with a dangling separator. The branch is
  not redundant ceremony: an empty `CoinSet` is a **meaningful domain state** (#10: empty = exact
  payment / zero change, as opposed to `null` = no combination possible). An exact sale is a complete
  success with nothing to hand back, so it renders as the bare code; the presentation faithfully mirrors
  the empty-vs-null distinction the domain already drew, rather than papering over an edge case.
- **Consequences:** `formatSale` composes `formatCoins` (a single source of coin rendering, inheriting
  the float-blindness of #22 and the highest-first ordering of #23). The `OutputFormatter` is now
  complete — `formatCoin` / `formatCoins` / `formatSale` — covering every CLI output: a sale's line, and
  RETURN-COIN's coins via `formatCoins` directly.

## 25. The CLI command grammar and the interpreter that dispatches it

- **Context:** The brief's examples are comma-separated command sequences ("1, 0.25, 0.25, GET-SODA")
  and explicitly leave the interface to the candidate; the SERVICE syntax in particular is unspecified.
  The CLI must turn this text into calls on the `MachineDriver` driving port.
- **Decision:** A line is a **comma-separated token stream executed left to right**. A token starting
  with a digit is a coin (parsed by `CoinParser` -> `insertCoin`); `GET-<code>` selects an item;
  `RETURN-COIN` returns the tray; `SERVICE` / `END-SERVICE` are the nullary mode transitions; and
  `SET-CHANGE` / `RESTOCK` are the two **variadic** commands that consume the rest of their line as
  operands (coin tokens, or `CODE:quantity` pairs). `CommandInterpreter` depends only on the port, does
  parse-route-render and nothing else, and classifies tokens by **leading digit**: a numeric-but-invalid
  token like "0.30" reaches the parser and fails as a precise `InvalidCoin`, while a non-keyword like
  "FOO" is an `InvalidCommand`.
- **Alternatives:** (a) one command per line — rejected: the brief itself puts several coins and a GET on
  a single line, so a line must be a sequence. (b) encode variadic operands inside one token
  (`SET-CHANGE=0.25+0.10`) — rejected: a second mini-grammar to parse and defend; consuming the rest of
  the line is simpler and mirrors the aggregate's **set semantics** (#14 — the technician declares the
  whole drawer/shelf at once). (c) route every non-keyword to the coin parser, so "FOO" also becomes an
  `InvalidCoin` — rejected: it conflates two distinct delivery errors; the leading-digit split keeps
  "malformed coin" and "unknown command" separate, which #26 then maps to different exit codes. (d) the
  interpreter depends on the concrete service — rejected: depending on the port means an HTTP adapter
  could drive the same use cases with no change to the application or domain.
- **Consequences:** The three brief examples fall out with no special-casing. `SET-CHANGE` / `RESTOCK`
  must be the last command on their line (a documented constraint of being variadic). The interpreter is
  unit-tested in isolation from the domain via a recording test double of the port, while the real
  end-to-end flow is covered by the acceptance suite (#27). Rendering (the `OutputFormatter`) and
  error-to-exit-code mapping (#26) are separate responsibilities, kept out of this class.

## 26. The CLI exit-code taxonomy leans on the exception hierarchy

- **Context:** The CLI must report each command's outcome to the shell and decide which failures are
  user-facing and which are bugs. The domain already split its errors into a recoverable
  `DomainException` (a `RuntimeException`) and a programming-bug `IllegalState` (a `LogicException`).
- **Decision:** `ErrorMapper` maps a **recoverable** error to a stable exit code: **0** success, **1** a
  domain refusal (the `DomainException` family — out of stock, insufficient funds, no change, a busy
  tray), **2** malformed input (`InvalidCoin` / `InvalidCommand`). The run loop catches only the
  `RuntimeException` family and writes its message to stderr; `IllegalState` is **never caught**, so a
  driver bug bubbles out with its stack trace. The mapper does not re-decide what is recoverable — the
  exception hierarchy already encodes that — it only assigns presentation to errors already known to be
  recoverable.
- **Alternatives:** (a) a single nonzero code for every error — rejected: separating a usage error (bad
  input) from an operational refusal (the machine said no) is the conventional Unix split (2 vs 1) and is
  cheaply useful when the CLI is scripted. (b) have the mapper return null for fatal errors and let the
  caller rethrow — rejected: it duplicates the recoverable-vs-bug knowledge that the type hierarchy
  already holds; catching `RuntimeException` at the boundary is the idiomatic expression of the same
  rule. (c) catch `Throwable` and turn `IllegalState` into a tidy message — rejected: dressing a
  programming bug as a user error hides it; a bug that a correct driver can never trigger should fail
  loudly, not silently.
- **Consequences:** The recoverable/fatal line is drawn exactly once, in the exception types, and both
  the boundary `catch` and the mapper read it rather than restating it. The mapper is a tiny pure policy
  tested in isolation. A piped batch exits nonzero whenever any command was refused (carrying the most
  recent recoverable error's code), while a driver bug aborts the process with a trace.

## 27. A tested composition root and a three-line entrypoint; acceptance in two layers

- **Context:** Something must wire the concrete adapters (the in-memory repository, the backtracking
  strategy, the parser/formatter) and seed a machine so the brief's examples run out of the box. The
  `bin/` directory is intentionally outside the PHPStan and coding-style paths.
- **Decision:** `CliBootstrap` — a tested, statically analyzed factory in `Infrastructure/Cli` — is the
  single place that names concrete classes and **provisions the default machine through its own SERVICE
  operations** (`enterService` -> `setAvailableChange` -> `restockItems` -> `leaveService`), exactly as a
  technician would. `bin/vending` is a three-line shim: load the autoloader and run the application over
  `STDIN`/`STDOUT`/`STDERR`, returning its exit code. Acceptance is verified in **two layers**: the three
  brief examples in-process through the composition root, plus one **real-subprocess smoke** that pipes a
  purchase through the packaged binary over `proc_open` pipes.
- **Alternatives:** (a) put the wiring and provisioning directly in `bin/vending` — rejected: that code
  would be neither tested nor type-checked (the entrypoint is out of the analysis paths); keeping the
  logic in a class is what lets the shim be trivially correct. (b) ship an empty machine and require a
  SERVICE session before any sale — rejected for the default binary, because the brief examples would not
  run standalone; provisioning via the machine's own service API is honest (no backdoor into private
  state) and a real deployment would load the same seed from configuration behind the repository port.
  (c) only a subprocess acceptance test — rejected: slower and more awkward to assert than driving the
  application in-process; the in-process layer covers the cases while the single subprocess smoke proves
  the real autoloader, entrypoint and stream wiring.
- **Consequences:** `bin/vending`'s lack of static analysis is harmless because it carries no logic. The
  demo catalog, change float and initial stock are clearly labelled seed data, not domain rules. The
  acceptance suite does double duty: it documents the brief's expected behaviour and guards the shipped
  artifact against regressions.
