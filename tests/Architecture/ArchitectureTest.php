<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture boundaries enforced inside the PHPStan pass via PHPat — no separate tool or second
 * dependency graph, so `composer stan` is the single gate that also checks layering.
 *
 * The domain is the innermost layer of the hexagon, so it must depend on nothing outside itself:
 * neither the application that orchestrates it nor the infrastructure adapters around it. That is
 * what lets persistence (the repository), delivery (CLI/HTTP) and any future adapter be swapped
 * without touching the core. The application layer has no classes yet, so that target enforces
 * vacuously today and starts biting the moment step 8 introduces it (which will also add the
 * Application -> Infrastructure rule).
 */
final class ArchitectureTest
{
    public function test_the_domain_depends_on_nothing_outside_itself(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('VendingMachine\Domain'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('VendingMachine\Application'),
                Selector::inNamespace('VendingMachine\Infrastructure'),
            )
            ->because('the domain is the innermost hexagon layer and must stay independent of the application and infrastructure around it');
    }
}
