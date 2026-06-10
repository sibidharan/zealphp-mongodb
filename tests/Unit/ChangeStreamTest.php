<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use Iterator;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\ChangeStream;
use ZealPHP\MongoDB\Exception\RuntimeException;

use function class_implements;
use function function_exists;

/**
 * ChangeStream became a REAL server change stream in v0.3.2 — behavioral
 * coverage (event delivery, pipelines, resume tokens, updateLookup) lives in
 * tests/Integration/ChangeStreamTest.php against a replica set. Without the
 * ext only the structural contract and the fail-fast guard are testable.
 */
class ChangeStreamTest extends TestCase
{
    public function testImplementsIterator(): void
    {
        $this->assertContains(Iterator::class, (array) class_implements(ChangeStream::class));
    }

    public function testConstructorFailsFastWithoutExtFunctions(): void
    {
        if (function_exists('zealphp_mongodb_change_stream_next')) {
            $this->markTestSkipped('ext with change stream support loaded — guard not reachable');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zealphp_mongodb.so >= 0.3.2');
        new ChangeStream(0);
    }
}
