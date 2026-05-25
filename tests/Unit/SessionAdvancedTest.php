<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Session;

class SessionAdvancedTest extends TestCase
{
    // ── Stub return values ──────────────────────────────────────────

    public function testGetTransactionOptionsReturnsNull(): void
    {
        $s = new Session(0);
        $this->assertNull($s->getTransactionOptions());
    }

    public function testGetTransactionOptionsNullDuringTransaction(): void
    {
        $s = new Session(0);
        $s->startTransaction();
        $this->assertNull($s->getTransactionOptions());
    }

    public function testGetClusterTimeReturnsNull(): void
    {
        $s = new Session(0);
        $this->assertNull($s->getClusterTime());
    }

    public function testGetOperationTimeReturnsNull(): void
    {
        $s = new Session(0);
        $this->assertNull($s->getOperationTime());
    }

    public function testGetServerReturnsNull(): void
    {
        $s = new Session(0);
        $this->assertNull($s->getServer());
    }

    public function testIsDirtyReturnsFalse(): void
    {
        $s = new Session(0);
        $this->assertFalse($s->isDirty());
    }

    public function testIsDirtyFalseAfterTransaction(): void
    {
        $s = new Session(0);
        $s->startTransaction();
        $s->commitTransaction();
        $this->assertFalse($s->isDirty());
    }

    // ── Advance no-op methods ───────────────────────────────────────

    public function testAdvanceClusterTimeWithArrayDoesNotThrow(): void
    {
        $s = new Session(0);
        $s->advanceClusterTime(['clusterTime' => 1]);

        // No exception means success; verify session is still usable
        $this->assertNull($s->getClusterTime());
    }

    public function testAdvanceClusterTimeWithObjectDoesNotThrow(): void
    {
        $s = new Session(0);
        $s->advanceClusterTime((object) ['clusterTime' => 1]);

        $this->assertNull($s->getClusterTime());
    }

    public function testAdvanceOperationTimeDoesNotThrow(): void
    {
        $s = new Session(0);
        $s->advanceOperationTime(12345);

        // No exception means success; verify session is still usable
        $this->assertNull($s->getOperationTime());
    }

    public function testAdvanceOperationTimeWithNullDoesNotThrow(): void
    {
        $s = new Session(0);
        $s->advanceOperationTime(null);

        $this->assertNull($s->getOperationTime());
    }

    // ── Transaction state constants ─────────────────────────────────

    public function testTransactionNoneConstant(): void
    {
        $this->assertSame('none', Session::TRANSACTION_NONE);
    }

    public function testTransactionStartingConstant(): void
    {
        $this->assertSame('starting', Session::TRANSACTION_STARTING);
    }

    public function testTransactionInProgressConstant(): void
    {
        $this->assertSame('in_progress', Session::TRANSACTION_IN_PROGRESS);
    }

    public function testTransactionCommittedConstant(): void
    {
        $this->assertSame('committed', Session::TRANSACTION_COMMITTED);
    }

    public function testTransactionAbortedConstant(): void
    {
        $this->assertSame('aborted', Session::TRANSACTION_ABORTED);
    }

    // ── Session with options ────────────────────────────────────────

    public function testSessionAcceptsOptions(): void
    {
        $s = new Session(1, ['causalConsistency' => true]);

        // Session should be created without error and be functional
        $this->assertSame(Session::TRANSACTION_NONE, $s->getTransactionState());
        $this->assertFalse($s->isInTransaction());
    }

    public function testLogicalSessionIdIsUnique(): void
    {
        $s1 = new Session(0);
        $s2 = new Session(0);

        $id1 = $s1->getLogicalSessionId();
        $id2 = $s2->getLogicalSessionId();

        $this->assertNotSame($id1->id, $id2->id);
    }
}
