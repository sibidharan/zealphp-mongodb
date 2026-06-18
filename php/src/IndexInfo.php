<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use function in_array;

class IndexInfo extends Document
{
    public function getName(): string
    {
        return $this['name'] ?? '';
    }

    public function getKey(): array
    {
        $key = $this['key'] ?? [];

        return $key instanceof Document ? $key->getArrayCopy() : (array) $key;
    }

    public function getNamespace(): string
    {
        return $this['ns'] ?? '';
    }

    public function getVersion(): int
    {
        return $this['v'] ?? 0;
    }

    public function isUnique(): bool
    {
        return (bool) ($this['unique'] ?? false);
    }

    public function isSparse(): bool
    {
        return (bool) ($this['sparse'] ?? false);
    }

    public function isTtl(): bool
    {
        return isset($this['expireAfterSeconds']);
    }

    public function getExpireAfterSeconds(): int
    {
        return $this['expireAfterSeconds'] ?? 0;
    }

    public function is2dSphere(): bool
    {
        return in_array('2dsphere', $this->getKey(), true);
    }

    public function isText(): bool
    {
        // A text index is stored by the server with a synthetic `_fts` key.
        return isset($this->getKey()['_fts']);
    }
}
