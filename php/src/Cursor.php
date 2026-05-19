<?php
namespace ZealPHP\MongoDB;

class Cursor implements \Iterator
{
    private Document|array|null $current = null;
    private int $key = -1;
    private bool $started = false;

    public function __construct(private int $cursorId) {}

    public function current(): Document|array|null { return $this->current; }
    public function key(): int { return $this->key; }
    public function valid(): bool { return $this->current !== null; }
    public function rewind(): void
    {
        if (!$this->started) {
            $this->started = true;
            $this->next();
        }
    }

    public function next(): void
    {
        $raw = zealphp_mongodb_cursor_next($this->cursorId);
        $this->current = $raw !== null ? Collection::wrapDoc($raw) : null;
        $this->key++;
    }

    public function toArray(): array
    {
        $results = [];
        if (!$this->started) {
            $this->rewind();
        }
        if ($this->current !== null) {
            $results[] = $this->current;
        }
        while (($doc = zealphp_mongodb_cursor_next($this->cursorId)) !== null) {
            $results[] = Collection::wrapDoc($doc);
        }
        $this->current = null;
        return $results;
    }

    public function __destruct()
    {
        @zealphp_mongodb_cursor_close($this->cursorId);
    }
}
