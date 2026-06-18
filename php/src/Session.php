<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use function function_exists;
use function in_array;
use function zealphp_mongodb_session_abort_transaction;
use function zealphp_mongodb_session_cluster_time;
use function zealphp_mongodb_session_commit_transaction;
use function zealphp_mongodb_session_end;
use function zealphp_mongodb_session_lsid;
use function zealphp_mongodb_session_operation_time;
use function zealphp_mongodb_session_start;
use function zealphp_mongodb_session_start_transaction;

/**
 * Server-backed client session — real `ClientSession` in the Rust driver,
 * matching ext-mongodb's MongoDB\Driver\Session semantics. Pass it to
 * collection operations via the `['session' => $session]` option; in a
 * transaction every such op rides the same server transaction.
 *
 * ```php
 * $session = $client->startSession();
 * $session->startTransaction();
 * try {
 *     $col->insertOne(['x' => 1], ['session' => $session]);
 *     $col->updateOne(['y' => 2], ['$set' => ['z' => 3]], ['session' => $session]);
 *     $session->commitTransaction();
 * } catch (\Throwable $e) {
 *     $session->abortTransaction();
 *     throw $e;
 * } finally {
 *     $session->endSession();
 * }
 * ```
 *
 * Transactions require a replica set or sharded cluster (MongoDB server
 * requirement — same as the C driver).
 */
class Session
{
    public const TRANSACTION_NONE = 'none';
    public const TRANSACTION_STARTING = 'starting';
    public const TRANSACTION_IN_PROGRESS = 'in_progress';
    public const TRANSACTION_COMMITTED = 'committed';
    public const TRANSACTION_ABORTED = 'aborted';

    private string $transactionState = self::TRANSACTION_NONE;

    /** @var array<string, mixed>|null */
    private array|null $transactionOptions = null;

    private readonly int $sessionId;

    private bool $ended = false;

    public function __construct(private readonly int $poolId, private readonly array $options = [])
    {
        if (! function_exists('zealphp_mongodb_session_start')) {
            throw new Exception\RuntimeException(
                'Sessions require zealphp_mongodb.so >= 0.3.2 (zealphp_mongodb_session_start missing).',
            );
        }

        // Assign to a local first — ext-php-rs marks Option<&Zval> args
        // by-ref-nullable, and PHP refuses to pass an EXPRESSION by
        // reference (the issue-#3 quirk; every Collection op does the same).
        $opts = $options ?: null;
        $this->sessionId = zealphp_mongodb_session_start($this->poolId, $opts);
    }

    /**
     * Internal — the ext-side session registry handle, threaded through ops.
     * Used by Collection::mapOptions() once per operation that rides this
     * session, so it doubles as the transaction-state transition point that
     * matches MongoDB\Driver\Session: the first op in a transaction moves
     * `starting` -> `in_progress` (#20), and an op that reuses the session
     * after a finished transaction resets the state to `none` (#62).
     */
    public function getSessionId(): int
    {
        if ($this->transactionState === self::TRANSACTION_STARTING) {
            $this->transactionState = self::TRANSACTION_IN_PROGRESS;
        } elseif (in_array($this->transactionState, [self::TRANSACTION_COMMITTED, self::TRANSACTION_ABORTED], true)) {
            $this->transactionState = self::TRANSACTION_NONE;
            $this->transactionOptions = null;
        }

        return $this->sessionId;
    }

    /**
     * Start a server transaction on this session. State errors (already in
     * transaction, …) surface as the driver's own exceptions — same contract
     * as ext-mongodb.
     */
    public function startTransaction(array|null $options = null): void
    {
        $this->assertNotEnded();
        zealphp_mongodb_session_start_transaction($this->sessionId, $options);
        // Official semantics: a freshly-started transaction is `starting` until
        // its first operation runs (#20); the options are retained (#21).
        $this->transactionState = self::TRANSACTION_STARTING;
        $this->transactionOptions = $options;
    }

    public function commitTransaction(): void
    {
        $this->assertNotEnded();
        zealphp_mongodb_session_commit_transaction($this->sessionId);
        $this->transactionState = self::TRANSACTION_COMMITTED;
    }

    public function abortTransaction(): void
    {
        $this->assertNotEnded();
        zealphp_mongodb_session_abort_transaction($this->sessionId);
        $this->transactionState = self::TRANSACTION_ABORTED;
    }

    public function endSession(): void
    {
        if ($this->ended) {
            return;
        }

        zealphp_mongodb_session_end($this->sessionId);
        $this->ended = true;
        $this->transactionState = self::TRANSACTION_NONE;
    }

    public function isInTransaction(): bool
    {
        return in_array($this->transactionState, [self::TRANSACTION_STARTING, self::TRANSACTION_IN_PROGRESS], true);
    }

    public function getTransactionState(): string
    {
        return $this->transactionState;
    }

    public function getTransactionOptions(): array|null
    {
        return $this->transactionOptions;
    }

    /** Logical session id document ({"id": Binary(UUID)}), like the C driver. */
    public function getLogicalSessionId(): object
    {
        $this->assertNotEnded();

        return (object) zealphp_mongodb_session_lsid($this->sessionId);
    }

    public function getClusterTime(): object|null
    {
        $this->assertNotEnded();
        $ct = zealphp_mongodb_session_cluster_time($this->sessionId);

        return $ct === null ? null : (object) $ct;
    }

    public function getOperationTime(): object|null
    {
        $this->assertNotEnded();
        $ot = zealphp_mongodb_session_operation_time($this->sessionId);

        return $ot === null ? null : (object) $ot;
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
        // No-op: the Rust driver advances cluster time automatically on every
        // server response; manual gossip advancement is not exposed.
    }

    public function advanceOperationTime(object $operationTime): void
    {
        // No-op: see advanceClusterTime().
    }

    public function __destruct()
    {
        $this->endSession();
    }

    private function assertNotEnded(): void
    {
        if ($this->ended) {
            throw new Exception\RuntimeException('Session has already been ended.');
        }
    }
}
