<?php

declare(strict_types=1);

namespace VendingMachine\Tests\Support;

use function fopen;
use function fwrite;

use PHPUnit\Framework\Assert;

use function rewind;
use function stream_get_contents;

/**
 * Test helpers for driving the CLI over in-memory streams, shared by the run-loop and acceptance tests
 * so the stream plumbing is written once.
 */
trait InMemoryStreams
{
    /**
     * @return resource
     */
    private function memoryStream(string $contents = '')
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            Assert::fail('Could not open an in-memory stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contentsOf($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);

        return $contents === false ? '' : $contents;
    }
}
