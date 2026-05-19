<?php
namespace ZealPHP\MongoDB;

class IndexInfo extends Document
{
    public function getName(): string
    {
        return $this['name'] ?? '';
    }

    public function getKey(): array
    {
        $key = $this['key'] ?? [];
        return $key instanceof Document ? $key->getArrayCopy() : (array)$key;
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
        return (bool)($this['unique'] ?? false);
    }

    public function isSparse(): bool
    {
        return (bool)($this['sparse'] ?? false);
    }

    public function isTtl(): bool
    {
        return isset($this['expireAfterSeconds']);
    }
}
