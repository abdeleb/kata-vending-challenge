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
 * without touching the core. The application layer sits between them: it orchestrates the domain
 * through ports and must not reach for a concrete infrastructure adapter, which the composition root
 * is responsible for wiring in.
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

    public function test_the_application_depends_on_nothing_in_infrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('VendingMachine\Application'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('VendingMachine\Infrastructure'))
            ->because('the application orchestrates the domain through ports; concrete adapters are wired in by the composition root, never referenced directly');
    }
}
