<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Iterator;

use function array_is_list;
use function array_slice;
use function is_array;
use function usort;

class ArrayCursor implements Iterator
{
    private int $pos = 0;

    public function __construct(private array $docs)
    {
        foreach ($this->docs as $i => $doc) {
            if (! is_array($doc) || array_is_list($doc)) {
                continue;
            }

            $this->docs[$i] = Collection::wrapDoc($doc);
        }
    }

    public function current(): Document|array|null
    {
        return $this->docs[$this->pos] ?? null;
    }

    public function key(): int
    {
        return $this->pos;
    }

    public function next(): void
    {
        $this->pos++;
    }

    public function rewind(): void
    {
        $this->pos = 0;
    }

    public function valid(): bool
    {
        return isset($this->docs[$this->pos]);
    }

    public function toArray(): array
    {
        return $this->docs;
    }

    public function sort(array $sort): self
    {
        usort($this->docs, static function ($a, $b) use ($sort) {
            foreach ($sort as $field => $dir) {
                $va = $a[$field] ?? null;
                $vb = $b[$field] ?? null;
                $cmp = $va <=> $vb;
                if ($cmp !== 0) {
                    return $dir > 0 ? $cmp : -$cmp;
                }
            }

            return 0;
        });

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->docs = array_slice($this->docs, 0, $limit);

        return $this;
    }

    public function skip(int $skip): self
    {
        $this->docs = array_slice($this->docs, $skip);

        return $this;
    }
}
