<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use function bin2hex;
use function random_bytes;

class Session
{
    public const TRANSACTION_NONE = 'none';
    public const TRANSACTION_STARTING = 'starting';
    public const TRANSACTION_IN_PROGRESS = 'in_progress';
    public const TRANSACTION_COMMITTED = 'committed';
    public const TRANSACTION_ABORTED = 'aborted';

    private string $transactionState = self::TRANSACTION_NONE;

    public function __construct(private readonly int $poolId, private readonly array $options = [])
    {
    }

    public function startTransaction(array|null $options = null): void
    {
        $this->transactionState = self::TRANSACTION_IN_PROGRESS;
    }

    public function commitTransaction(): void
    {
        $this->transactionState = self::TRANSACTION_COMMITTED;
    }

    public function abortTransaction(): void
    {
        $this->transactionState = self::TRANSACTION_ABORTED;
    }

    public function endSession(): void
    {
        $this->transactionState = self::TRANSACTION_NONE;
    }

    public function isInTransaction(): bool
    {
        return $this->transactionState === self::TRANSACTION_IN_PROGRESS;
    }

    public function getTransactionState(): string
    {
        return $this->transactionState;
    }

    public function getTransactionOptions(): array|null
    {
        return null;
    }

    public function getLogicalSessionId(): object
    {
        return (object) ['id' => bin2hex(random_bytes(16))];
    }

    public function getClusterTime(): object|null
    {
        return null;
    }

    public function getOperationTime(): object|null
    {
        return null;
    }

    public function getServer(): object|null
    {
        return null;
    }

    public function isDirty(): bool
    {
        return false;
    }

    public function advanceClusterTime(array|object $clusterTime): void
    {
    }

    public function advanceOperationTime(mixed $operationTime): void
    {
    }
}
