<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

/**
 * One entry of Client::listDatabases(), mirroring MongoDB\Model\DatabaseInfo:
 * exposes the database name plus the server-reported sizeOnDisk and empty flag
 * (array access to the raw spec still works, since it extends Document).
 */
class DatabaseInfo extends Document
{
    public function getName(): string
    {
        return $this['name'] ?? '';
    }

    public function getSizeOnDisk(): int
    {
        return (int) ($this['sizeOnDisk'] ?? 0);
    }

    public function isEmpty(): bool
    {
        return (bool) ($this['empty'] ?? false);
    }
}
