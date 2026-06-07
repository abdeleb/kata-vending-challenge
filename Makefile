DC  := docker compose
RUN := $(DC) run --rm app

.DEFAULT_GOAL := help

.PHONY: help build install test stan cs cs-fix ci check-leak run shell

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image
	$(DC) build

install: ## Install Composer dependencies (generates vendor/ and composer.lock)
	$(RUN) composer install

test: ## Run the PHPUnit test suite
	$(RUN) composer test

stan: ## Run PHPStan (level max); will also enforce layer boundaries via PHPat once layers exist
	$(RUN) composer stan

cs: ## Check coding style (dry run)
	$(RUN) composer cs

cs-fix: ## Fix coding style in place
	$(RUN) composer cs:fix

ci: ## Run the full local quality gate (cs + stan + tests)
	$(RUN) composer ci

check-leak: ## Local-only: fail if any pattern in the gitignored .forbidden file appears in tracked files
	@if [ ! -f .forbidden ]; then echo "check-leak: no .forbidden file, skipping"; exit 0; fi; \
	if git grep -I -i -n -f .forbidden -- ':!composer.lock' >/dev/null 2>&1; then \
		echo "check-leak: forbidden token found in a tracked file:"; \
		git grep -I -i -n -f .forbidden -- ':!composer.lock'; \
		exit 1; \
	fi; \
	echo "check-leak: clean"

run: ## Start the CLI over stdin (type commands, or pipe them in)
	$(RUN) php bin/vending

shell: ## Open an interactive shell in the container
	$(RUN) sh
