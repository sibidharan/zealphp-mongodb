<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Iterator;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Event;

use function zealphp_mongodb_async_result;
use function zealphp_mongodb_close_efd;
use function zealphp_mongodb_cursor_close;
use function zealphp_mongodb_cursor_next_batch_async;

class AsyncCursor implements Iterator
{
    private Document|array|null $current = null;
    private int $key = -1;
    private bool $started = false;
    private bool $exhausted;
    private array $buffer;
    private ?int $cursorId;
    private const BATCH_SIZE = 100;

    public function __construct(?int $cursorId, array $initialDocs = [], bool $exhausted = false)
    {
        $this->cursorId = $cursorId;
        $this->buffer = $initialDocs;
        $this->exhausted = $exhausted;
    }

    public function current(): Document|array|null
    {
        return $this->current;
    }

    public function key(): int
    {
        return $this->key;
    }

    public function valid(): bool
    {
        return $this->current !== null;
    }

    public function rewind(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->advance();
    }

    public function next(): void
    {
        $this->advance();
    }

    private function advance(): void
    {
        if ($this->buffer) {
            $this->key++;
            $this->current = Collection::wrapDoc(array_shift($this->buffer));

            return;
        }

        if ($this->exhausted || $this->cursorId === null) {
            $this->current = null;
            $this->key++;

            return;
        }

        $this->fetchBatch();

        $this->key++;
        if ($this->buffer) {
            $this->current = Collection::wrapDoc(array_shift($this->buffer));
        } else {
            $this->current = null;
        }
    }

    private function fetchBatch(): void
    {
        $async = zealphp_mongodb_cursor_next_batch_async($this->cursorId, self::BATCH_SIZE);
        $efd = $async['efd'];
        $taskId = $async['task_id'];
        $chan = new Channel(1);

        Event::add($efd, static function () use ($chan, $taskId, $efd) {
            $json = zealphp_mongodb_async_result($taskId);
            Event::del($efd);
            zealphp_mongodb_close_efd($efd);
            $chan->push($json);
        });

        $json = $chan->pop(30.0);
        if ($json === false) {
            Event::del($efd);
            zealphp_mongodb_close_efd($efd);

            throw new Exception\RuntimeException('Cursor batch timeout');
        }

        $result = json_decode($json, true);
        if (is_array($result) && isset($result['__error'])) {
            throw new Exception\RuntimeException('Cursor error: ' . $result['__error']);
        }

        $this->buffer = $result['docs'] ?? [];
        if ($result['exhausted'] ?? true) {
            $this->exhausted = true;
        }
    }

    public function toArray(): array
    {
        $results = [];
        if (! $this->started) {
            $this->rewind();
        }

        if ($this->current !== null) {
            $results[] = $this->current;
        }

        while (true) {
            $this->advance();
            if ($this->current === null) {
                break;
            }

            $results[] = $this->current;
        }

        return $results;
    }

    public function __destruct()
    {
        if ($this->cursorId !== null) {
            @zealphp_mongodb_cursor_close($this->cursorId);
        }
    }
}
