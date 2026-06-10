<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Iterator;
use ReturnTypeWillChange;

use function function_exists;
use function zealphp_mongodb_change_stream_close;
use function zealphp_mongodb_change_stream_next;
use function zealphp_mongodb_change_stream_resume_token;
use function zealphp_mongodb_watch;

/**
 * Real server change stream (mongo-php-library iteration semantics):
 *
 * ```php
 * $stream = $collection->watch([], ['maxAwaitTimeMS' => 500]);
 * while (true) {
 *     $stream->next();                  // blocks up to maxAwaitTimeMS
 *     if (! $stream->valid()) {
 *         continue;                     // timed out — poll again
 *     }
 *     $event = $stream->current();      // operationType, fullDocument, …
 * }
 * ```
 *
 * `key()` counts delivered events (0-based) and only advances on real
 * events, matching the official library. Requires a replica set / sharded
 * cluster (server-side change stream requirement).
 */
class ChangeStream implements Iterator
{
    private int $key = -1;

    private mixed $current = null;

    private bool $closed = false;

    public function __construct(
        private readonly int $streamId,
        private readonly int $maxAwaitMs = 1000,
    ) {
        if (! function_exists('zealphp_mongodb_change_stream_next')) {
            throw new Exception\RuntimeException(
                'Change streams require zealphp_mongodb.so >= 0.3.2 (zealphp_mongodb_change_stream_next missing).',
            );
        }
    }

    #[ReturnTypeWillChange]
    public function current(): mixed
    {
        return $this->current;
    }

    #[ReturnTypeWillChange]
    public function key(): mixed
    {
        return $this->current === null ? null : $this->key;
    }

    /**
     * Open a stream at client (db='' col=''), database (col='') or
     * collection scope. Shared factory behind Client/Database/Collection
     * ::watch(). Options: fullDocument, resumeAfter, startAfter,
     * maxAwaitTimeMS (drives both server batches and PHP-side polling),
     * batchSize.
     */
    public static function open(int $poolId, string $db, string $col, array $pipeline, array $options): self
    {
        if (! function_exists('zealphp_mongodb_watch')) {
            throw new Exception\RuntimeException(
                'Change streams require zealphp_mongodb.so >= 0.3.2 (zealphp_mongodb_watch missing).',
            );
        }

        $pipeline = (array) Collection::prepareBSON($pipeline);
        $opts = [];
        foreach (['fullDocument', 'maxAwaitTimeMS', 'batchSize'] as $k) {
            if (! isset($options[$k])) {
                continue;
            }

            $opts[$k] = $options[$k];
        }

        foreach (['resumeAfter', 'startAfter'] as $k) {
            if (! isset($options[$k])) {
                continue;
            }

            $opts[$k] = Collection::prepareBSON((array) $options[$k]);
        }

        $optsOrNull = $opts ?: null;
        $streamId = zealphp_mongodb_watch($poolId, $db, $col, $pipeline, $optsOrNull);

        return new self($streamId, (int) ($options['maxAwaitTimeMS'] ?? 1000));
    }

    /** Poll for the next event, waiting up to maxAwaitTimeMS. */
    public function next(): void
    {
        $this->poll($this->maxAwaitMs);
    }

    /** First poll — non-blocking, like the official library's rewind(). */
    public function rewind(): void
    {
        if ($this->key >= 0) {
            return; // already iterating; rewinding a change stream is a no-op
        }

        $this->poll(0);
    }

    public function valid(): bool
    {
        return $this->current !== null;
    }

    /** The latest resume token, or null before the first server batch. */
    public function getResumeToken(): object|null
    {
        $this->assertOpen();
        $token = zealphp_mongodb_change_stream_resume_token($this->streamId);

        return $token === null ? null : (object) $token;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        zealphp_mongodb_change_stream_close($this->streamId);
        $this->closed = true;
        $this->current = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function poll(int $timeoutMs): void
    {
        $this->assertOpen();
        $event = zealphp_mongodb_change_stream_next($this->streamId, $timeoutMs);

        if ($event === null) {
            $this->current = null;

            return;
        }

        $this->key++;
        $this->current = Collection::wrapDoc($event);
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new Exception\RuntimeException('Change stream has been closed.');
        }
    }
}
