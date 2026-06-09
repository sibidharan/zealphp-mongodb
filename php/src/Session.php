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

    /**
     * @throws Exception\RuntimeException Transactions are not yet wired to the
     * server. Failing loud is deliberate: silently pretending to start a
     * transaction (the pre-v0.3.1 behaviour) let every op run
     * non-transactionally while the caller believed it had ACID semantics —
     * the worst possible silent failure for a database driver.
     */
    public function startTransaction(array|null $options = null): void
    {
        throw new Exception\RuntimeException(
            'MongoDB transactions are not yet supported by zealphp-mongodb '
            . '(real ClientSession transactions land in v0.4.0). '
            . 'Refusing to fake it: operations would run NON-transactionally.',
        );
    }

    /** @throws Exception\RuntimeException See startTransaction(). */
    public function commitTransaction(): void
    {
        throw new Exception\RuntimeException(
            'MongoDB transactions are not yet supported by zealphp-mongodb (see startTransaction()).',
        );
    }

    /** @throws Exception\RuntimeException See startTransaction(). */
    public function abortTransaction(): void
    {
        throw new Exception\RuntimeException(
            'MongoDB transactions are not yet supported by zealphp-mongodb (see startTransaction()).',
        );
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
