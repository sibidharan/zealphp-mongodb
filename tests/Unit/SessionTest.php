<?php
namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Session;

class SessionTest extends TestCase
{
    public function testTransactionLifecycle(): void
    {
        $s = new Session(0);
        $this->assertSame(Session::TRANSACTION_NONE, $s->getTransactionState());
        $this->assertFalse($s->isInTransaction());

        $s->startTransaction();
        $this->assertSame(Session::TRANSACTION_IN_PROGRESS, $s->getTransactionState());
        $this->assertTrue($s->isInTransaction());

        $s->commitTransaction();
        $this->assertSame(Session::TRANSACTION_COMMITTED, $s->getTransactionState());

        $s->endSession();
        $this->assertSame(Session::TRANSACTION_NONE, $s->getTransactionState());
    }

    public function testAbortTransaction(): void
    {
        $s = new Session(0);
        $s->startTransaction();
        $s->abortTransaction();
        $this->assertSame(Session::TRANSACTION_ABORTED, $s->getTransactionState());
    }

    public function testLogicalSessionId(): void
    {
        $s = new Session(0);
        $id = $s->getLogicalSessionId();
        $this->assertIsObject($id);
        $this->assertObjectHasProperty('id', $id);
    }
}
