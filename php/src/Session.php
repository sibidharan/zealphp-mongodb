<?php
namespace ZealPHP\MongoDB;
class Session
{
    const TRANSACTION_NONE = 'none';
    const TRANSACTION_STARTING = 'starting';
    const TRANSACTION_IN_PROGRESS = 'in_progress';
    const TRANSACTION_COMMITTED = 'committed';
    const TRANSACTION_ABORTED = 'aborted';

    private string $transactionState = self::TRANSACTION_NONE;

    public function __construct(private int $poolId, private array $options = []) {}

    public function startTransaction(?array $options = null): void { $this->transactionState = self::TRANSACTION_IN_PROGRESS; }
    public function commitTransaction(): void { $this->transactionState = self::TRANSACTION_COMMITTED; }
    public function abortTransaction(): void { $this->transactionState = self::TRANSACTION_ABORTED; }
    public function endSession(): void { $this->transactionState = self::TRANSACTION_NONE; }

    public function isInTransaction(): bool { return $this->transactionState === self::TRANSACTION_IN_PROGRESS; }
    public function getTransactionState(): string { return $this->transactionState; }
    public function getTransactionOptions(): ?array { return null; }
    public function getLogicalSessionId(): object { return (object)['id' => bin2hex(random_bytes(16))]; }
    public function getClusterTime(): ?object { return null; }
    public function getOperationTime(): ?object { return null; }
    public function getServer(): ?object { return null; }
    public function isDirty(): bool { return false; }
    public function advanceClusterTime(array|object $clusterTime): void {}
    public function advanceOperationTime(mixed $operationTime): void {}
}
