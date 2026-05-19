<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Iterator;

class ChangeStream implements Iterator
{
    public function current(): mixed
    {
        return null;
    }

    public function key(): mixed
    {
        return null;
    }

    public function next(): void
    {
    }

    public function rewind(): void
    {
    }

    public function valid(): bool
    {
        return false;
    }

    public function getResumeToken(): object|null
    {
        return null;
    }
}
