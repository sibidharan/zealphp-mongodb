<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Parity;

use MongoDB\BSON\Persistable;

/** A trivial Persistable used to verify __pclass round-tripping (#69). */
class ParityPersistable implements Persistable
{
    public function __construct(public string $name = '')
    {
    }

    /** @return array<string, mixed> */
    public function bsonSerialize(): array
    {
        return ['name' => $this->name];
    }

    /** @param array<string, mixed> $data */
    public function bsonUnserialize(array $data): void
    {
        $this->name = $data['name'] ?? '';
    }
}
