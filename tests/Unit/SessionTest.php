<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\Session;

use function function_exists;

/**
 * Session became a REAL server-backed ClientSession in v0.3.2 — behavioral
 * coverage (start/commit/abort, snapshot reads, lsid, operationTime) lives in
 * tests/Integration/TransactionTest.php against a replica set. The only thing
 * unit-testable without the ext is the constructor's fail-fast guard.
 */
class SessionTest extends TestCase
{
    public function testConstructorFailsFastWithoutExtFunctions(): void
    {
        if (function_exists('zealphp_mongodb_session_start')) {
            $this->markTestSkipped('ext with session support loaded — guard not reachable');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zealphp_mongodb.so >= 0.3.2');
        new Session(0);
    }

    public function testTransactionStateConstantsMatchCDriverVocabulary(): void
    {
        $this->assertSame('none', Session::TRANSACTION_NONE);
        $this->assertSame('starting', Session::TRANSACTION_STARTING);
        $this->assertSame('in_progress', Session::TRANSACTION_IN_PROGRESS);
        $this->assertSame('committed', Session::TRANSACTION_COMMITTED);
        $this->assertSame('aborted', Session::TRANSACTION_ABORTED);
    }
}
