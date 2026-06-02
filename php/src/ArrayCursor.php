<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Iterator;
use MongoDB\Model\BSONDocument;

use function array_slice;
use function is_array;
use function usort;

class ArrayCursor implements Iterator
{
    private int $pos = 0;

    public function __construct(private array $docs)
    {
        // Top-level cursor positions are always documents under official
        // ext-mongodb (BSONDocument with ARRAY_AS_PROPS). Match that contract
        // here so consumers can use object access (`$doc->field = 'x'`)
        // without per-call defensive coerces, AND so empty docs (`[]` —
        // for which `array_is_list` returns true in PHP 8.1+) don't slip
        // through as bare arrays that crash on property assignment.
        //
        // We can't delegate to Collection::wrapDoc() at the top level — its
        // list-detection branch would wrap `[]` as a BSONArray, which
        // *silently drops* property writes (ARRAY_AS_PROPS on a list-backed
        // ArrayObject) — strictly worse than a crash. Inline the document
        // wrap; reuse wrapDoc() for nested values.
        foreach ($this->docs as $i => $doc) {
            if (! is_array($doc)) {
                continue;
            }

            $wrapped = new BSONDocument();
            foreach ($doc as $key => $value) {
                $wrapped[$key] = is_array($value) ? Collection::wrapDoc($value) : $value;
            }

            $this->docs[$i] = $wrapped;
        }
    }

    public function current(): BSONDocument|Document|array|null
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
