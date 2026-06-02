<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Scaffolding smoke test: proves the toolchain is wired end to end
 * (autoloading, PHPUnit, the expected PHP runtime) before any domain code exists.
 */
final class SmokeTest extends TestCase
{
    public function test_runtime_is_php_8_3(): void
    {
        self::assertGreaterThanOrEqual(80300, PHP_VERSION_ID);
        self::assertLessThan(80400, PHP_VERSION_ID);
    }
}
