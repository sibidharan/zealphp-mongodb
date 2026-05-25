<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Iterator;

use function function_exists;
use function is_array;
use function zealphp_mongodb_cursor_close;
use function zealphp_mongodb_cursor_next;
use function zealphp_mongodb_cursor_to_array;
use function zealphp_mongodb_find;
use function zealphp_mongodb_find_all;

class Cursor implements Iterator
{
    private Document|array|null $current = null;
    private int $key                     = -1;
    private bool $started                = false;
    /** @var array{poolId: int, db: string, col: string, filter: array<string, mixed>, opts: array<string, mixed>|null}|null */
    private array|null $deferredQuery;

    public function __construct(private int|null $cursorId)
    {
        $this->deferredQuery = null;
    }

    /** @param array<string, mixed> $filter */
    public static function deferred(int $poolId, string $db, string $col, array $filter, array|null $opts): self
    {
        $c                = new self(0);
        $c->cursorId      = null;
        $c->deferredQuery = ['poolId' => $poolId, 'db' => $db, 'col' => $col, 'filter' => $filter, 'opts' => $opts];

        return $c;
    }

    private function ensureCursor(): void
    {
        if ($this->cursorId !== null || $this->deferredQuery === null) {
            return;
        }

        $q              = $this->deferredQuery;
        $this->cursorId = zealphp_mongodb_find($q['poolId'], $q['db'], $q['col'], $q['filter'], $q['opts']);
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
        $raw = zealphp_mongodb_cursor_next($this->cursorId) ?? null;
        $this->current = is_array($raw) ? Collection::wrapDoc($raw) : $raw;
        $this->key++;
    }

    /** @return list<Document|array<string, mixed>> */
    public function toArray(): array
    {
        if ($this->canUseFindAll()) {
            $q = $this->deferredQuery;
            $this->deferredQuery = null;
            $this->current = null;
            $this->started = true;
            $this->cursorId = null;

            $opts = $q['opts'] ?? [];
            return zealphp_mongodb_find_all($q['poolId'], $q['db'], $q['col'], $q['filter'], $opts) ?: [];
        }

        $this->ensureCursor();

        $results = [];
        if ($this->started && $this->current !== null) {
            $results[] = $this->current;
        }

        if (function_exists('zealphp_mongodb_cursor_to_array')) {
            $bulk = zealphp_mongodb_cursor_to_array($this->cursorId);
            if (is_array($bulk)) {
                foreach ($bulk as $raw) {
                    $results[] = is_array($raw) ? Collection::wrapDoc($raw) : $raw;
                }
            }
        } else {
            while (true) {
                $raw = zealphp_mongodb_cursor_next($this->cursorId);
                if ($raw === null || $raw === false) {
                    break;
                }

                $results[] = is_array($raw) ? Collection::wrapDoc($raw) : $raw;
            }
        }

        $this->current = null;
        $this->started = true;
        $this->cursorId = null;

        return $results;
    }

    private function canUseFindAll(): bool
    {
        return ! $this->started
            && $this->deferredQuery !== null
            && $this->cursorId === null
            && function_exists('zealphp_mongodb_find_all');
    }

    public function __destruct()
    {
        if ($this->cursorId === null) {
            return;
        }

        @zealphp_mongodb_cursor_close($this->cursorId);
    }
}
