# Vending Machine

A vending machine modeled as a **pure domain core** with a thin **CLI adapter**
(hexagonal architecture / ports & adapters). Money is handled in **integer cents** — never floats.

This repository is a senior backend coding challenge. It is evaluated on architecture,
extensibility and testing rather than on "just making it work".

## Requirements

Only **Docker** and **Docker Compose** are required — no local PHP or Composer install.
The dev environment is a containerized PHP 8.3 CLI.

## Getting started

```bash
make build      # build the PHP 8.3 image
make install    # install Composer dependencies (creates vendor/ and composer.lock)
make test       # run the test suite
```

Run `make` with no target to list everything available.

## Quality gates

```bash
make cs         # coding style check (PHP-CS-Fixer, dry run)
make cs-fix     # fix coding style in place
make stan       # static analysis (PHPStan, level max)
make ci         # the full local gate: cs + stan + tests
```

> Layer-boundary enforcement (Domain must not depend on Infrastructure, etc.) runs **inside**
> the PHPStan pass via [PHPat](https://github.com/carlosas/phpat) and is wired once the layers
> exist. `deptrac`, the originally-planned tool, was archived in 2025 and is intentionally not used.

## Reproducibility

`composer.lock` is committed. The Docker image pins the PHP minor tag; for stronger guarantees
it can be pinned by immutable digest (`php:8.3-cli@sha256:...`).

## License

MIT
