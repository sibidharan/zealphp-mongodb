<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\Session;

class SessionTest extends TestCase
{
    public function testFreshSessionIsNotInTransaction(): void
    {
        $s = new Session(0);
        $this->assertSame(Session::TRANSACTION_NONE, $s->getTransactionState());
        $this->assertFalse($s->isInTransaction());
    }

    /**
     * Transactions FAIL LOUD until the real ClientSession implementation
     * lands: the previous stub silently flipped a local state string while
     * every operation ran NON-transactionally — fake ACID, the worst silent
     * failure a database driver can have.
     */
    public function testStartTransactionFailsLoud(): void
    {
        $s = new Session(0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not yet supported');
        $s->startTransaction();
    }

    public function testCommitTransactionFailsLoud(): void
    {
        $s = new Session(0);
        $this->expectException(RuntimeException::class);
        $s->commitTransaction();
    }

    public function testAbortTransactionFailsLoud(): void
    {
        $s = new Session(0);
        $this->expectException(RuntimeException::class);
        $s->abortTransaction();
    }

    public function testLogicalSessionId(): void
    {
        $s = new Session(0);
        $id = $s->getLogicalSessionId();
        $this->assertIsObject($id);
        $this->assertObjectHasProperty('id', $id);
    }
}
