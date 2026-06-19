<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Iterator;
use MongoDB\Model\BSONDocument;
use Throwable;
use ZealPHP\MongoDB\Exception\ErrorMapper;
use ZealPHP\MongoDB\Exception\LogicException;

use function array_map;
use function function_exists;
use function is_array;
use function zealphp_mongodb_cursor_close;
use function zealphp_mongodb_cursor_next;
use function zealphp_mongodb_cursor_to_array;
use function zealphp_mongodb_find;
use function zealphp_mongodb_find_all;

class Cursor implements Iterator
{
    private BSONDocument|Document|array|null $current = null;
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

        $q = $this->deferredQuery;
        // Opening the cursor runs the find (server selection + first batch);
        // map a server-side failure — e.g. an unsatisfiable readPreference
        // (#67) — to the typed exception the official driver throws.
        try {
            $this->cursorId = zealphp_mongodb_find($q['poolId'], $q['db'], $q['col'], $q['filter'], $q['opts']);
        } catch (Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function current(): BSONDocument|Document|array|null
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
        // A cursor may be iterated only once. Official MongoDB\Driver\Cursor
        // throws on any rewind after iteration has begun — a second foreach, or
        // toArray()/iteration after partial consumption (#14). Silently
        // no-oping (the old behaviour) dropped already-consumed docs.
        if ($this->started) {
            throw new LogicException('Cursors cannot rewind after starting iteration');
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
        // Same single-iteration contract as rewind(): draining a cursor that has
        // already started iterating would silently drop the consumed docs (#14).
        if ($this->started) {
            throw new LogicException('Cursors cannot rewind after starting iteration');
        }

        if ($this->canUseFindAll()) {
            $q = $this->deferredQuery;
            $this->deferredQuery = null;
            $this->current = null;
            $this->started = true;
            $this->cursorId = null;

            $opts = $q['opts'] ?? [];

            // Map a server-side failure (e.g. an unsatisfiable readPreference,
            // #67) to the typed exception the official driver throws.
            try {
                $raw = zealphp_mongodb_find_all($q['poolId'], $q['db'], $q['col'], $q['filter'], $opts) ?: [];
            } catch (Throwable $e) {
                throw ErrorMapper::map($e);
            }

            return array_map(
                static fn ($doc) => is_array($doc) ? Collection::wrapDoc($doc) : $doc,
                $raw,
            );
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
