<?php
namespace ZealPHP\MongoDB\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Exception\ExceptionInterface;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\Exception\ConnectionException;
use ZealPHP\MongoDB\Exception\AuthenticationException;
use ZealPHP\MongoDB\Exception\ConnectionTimeoutException;
use ZealPHP\MongoDB\Exception\ServerException;
use ZealPHP\MongoDB\Exception\CommandException;
use ZealPHP\MongoDB\Exception\ExecutionTimeoutException;
use ZealPHP\MongoDB\Exception\BulkWriteException;
use ZealPHP\MongoDB\Exception\LogicException;
use ZealPHP\MongoDB\Exception\InvalidArgumentException;
use ZealPHP\MongoDB\Exception\UnexpectedValueException;

class ExceptionHierarchyTest extends TestCase
{
    public function testRuntimeExceptionHierarchy(): void
    {
        $e = new RuntimeException('test');
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertInstanceOf(ExceptionInterface::class, $e);
    }

    public function testConnectionHierarchy(): void
    {
        $e = new AuthenticationException('auth failed');
        $this->assertInstanceOf(ConnectionException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertInstanceOf(ExceptionInterface::class, $e);
    }

    public function testServerHierarchy(): void
    {
        $e = new CommandException('bad command', 59);
        $this->assertInstanceOf(ServerException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testCommandExceptionResultDocument(): void
    {
        $result = (object)['ok' => 0, 'errmsg' => 'fail'];
        $e = new CommandException('fail', 59, null, $result);
        $this->assertSame($result, $e->getResultDocument());
    }

    public function testBulkWriteExceptionWriteResult(): void
    {
        $wr = (object)['nInserted' => 0];
        $e = new BulkWriteException('fail', 0, null, $wr);
        $this->assertSame($wr, $e->getWriteResult());
    }

    public function testHasErrorLabel(): void
    {
        $e = new RuntimeException('test');
        $this->assertFalse($e->hasErrorLabel('TransientTransactionError'));
    }

    public function testLogicException(): void
    {
        $e = new LogicException('logic');
        $this->assertInstanceOf(\LogicException::class, $e);
        $this->assertInstanceOf(ExceptionInterface::class, $e);
    }

    public function testInvalidArgumentException(): void
    {
        $e = new InvalidArgumentException('arg');
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertInstanceOf(ExceptionInterface::class, $e);
    }

    public function testUnexpectedValueException(): void
    {
        $e = new UnexpectedValueException('val');
        $this->assertInstanceOf(\UnexpectedValueException::class, $e);
        $this->assertInstanceOf(ExceptionInterface::class, $e);
    }
}
