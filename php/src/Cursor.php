<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Iterator;

use function zealphp_mongodb_cursor_close;
use function zealphp_mongodb_cursor_next;
use function zealphp_mongodb_cursor_to_array;
use function zealphp_mongodb_find;
use function zealphp_mongodb_find_all;

class Cursor implements Iterator
{
    private Document|array|null $current = null;
    private int $key = -1;
    private bool $started = false;
    private ?int $cursorId;
    private ?array $deferredQuery;

    public function __construct(int $cursorId)
    {
        $this->cursorId = $cursorId;
        $this->deferredQuery = null;
    }

    public static function deferred(int $poolId, string $db, string $col, array $filter, ?array $opts): self
    {
        $c = new self(0);
        $c->cursorId = null;
        $c->deferredQuery = ['poolId' => $poolId, 'db' => $db, 'col' => $col, 'filter' => $filter, 'opts' => $opts];

        return $c;
    }

    private function ensureCursor(): void
    {
        if ($this->cursorId === null && $this->deferredQuery !== null) {
            $q = $this->deferredQuery;
            $this->cursorId = zealphp_mongodb_find($q['poolId'], $q['db'], $q['col'], $q['filter'], $q['opts']);
        }
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
        $this->next();
    }

    public function next(): void
    {
        $this->ensureCursor();
        $raw = zealphp_mongodb_cursor_next($this->cursorId);
        $this->current = $raw !== null ? $raw : null;
        $this->key++;
    }

    public function toArray(): array
    {
        if (! $this->started && $this->deferredQuery !== null) {
            $q = $this->deferredQuery;
            $this->deferredQuery = null;
            $results = zealphp_mongodb_find_all($q['poolId'], $q['db'], $q['col'], $q['filter'], $q['opts']);
            $this->current = null;
            $this->started = true;

            return is_array($results) ? $results : [];
        }

        $this->ensureCursor();
        $results = [];
        if ($this->started && $this->current !== null) {
            $results[] = $this->current;
        }

        $raw = zealphp_mongodb_cursor_to_array($this->cursorId);
        if (is_array($raw)) {
            foreach ($raw as $doc) {
                $results[] = $doc;
            }
        }

        $this->current = null;
        $this->started = true;

        return $results;
    }

    public function __destruct()
    {
        if ($this->cursorId !== null) {
            @zealphp_mongodb_cursor_close($this->cursorId);
        }
    }
}
