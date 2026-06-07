# Vending Machine

A vending machine with a **pure domain core** and a thin **CLI adapter** (hexagonal architecture).
Money is integer cents — never floats.

## Install & run

You only need **Docker** installed — nothing else.

```bash
docker compose build                            # build the PHP 8.3 image (first time only)
docker compose run --rm app composer install    # install dependencies
docker compose run --rm app php bin/vending      # start the machine
```

Then type a command and press enter:

```text
1, 0.25, 0.25, GET-SODA
SODA
```

Or pipe a command straight in:

```bash
echo '1, GET-WATER' | docker compose run --rm -T app php bin/vending
# WATER, 0.25, 0.10
```

That's it — `docker compose run --rm app composer test` runs the tests, and `composer ci` the full gate.

> **Shortcut:** with `make` installed, the same commands are `make build`, `make install`, `make run`,
> `make test`, `make ci` (run `make` with no target to list them).

## Commands

A line is a comma-separated list of commands. Coins add up until a `GET` or `RETURN-COIN` resolves them.

| Command                       | What it does                       |
| ----------------------------- | ---------------------------------- |
| `0.05` `0.10` `0.25` `1`      | insert a coin                      |
| `GET-<CODE>`                  | buy a product                      |
| `RETURN-COIN`                 | give the inserted coins back       |
| `SERVICE` / `END-SERVICE`     | enter / leave service mode         |
| `SET-CHANGE, 0.25, 0.10`      | *(service)* set the change drawer  |
| `RESTOCK, SODA:5, WATER:3`    | *(service)* set the stock          |

`SET-CHANGE` and `RESTOCK` take the rest of the line as their operands, so each must be the **last
command on its line** — anything after them is rejected, not silently dropped.

The machine returns exact change or **refuses the sale** — it never short-changes you. It comes
stocked with `WATER` (0.65), `SODA` (1.50) and `JUICE` (1.00).

Exit code: `0` success · `1` refused (no stock / funds / change) · `2` bad input.

## How it's built

```text
src/Domain/           business core — no framework, no I/O
src/Application/       use-case ports + orchestrating service
src/Infrastructure/   CLI adapter + in-memory persistence
bin/vending           entrypoint
```

The dependency rule (the domain depends on nothing) is enforced by PHPStan + PHPat, and the full gate
(`composer ci` — style, static analysis at level max, tests) runs in CI on every push. The reasoning behind every
design choice is in [`docs/decision-log.md`](docs/decision-log.md).

## License

MIT
