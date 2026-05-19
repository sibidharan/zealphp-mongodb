<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use function array_map;
use function is_array;

class InsertManyResult
{
    public function __construct(private array $result)
    {
    }

    public function getInsertedCount(): int
    {
        return $this->result['inserted_count'] ?? 0;
    }

    public function getInsertedIds(): array
    {
        return array_map(
            static fn ($id) => is_array($id) ? Collection::wrapDoc($id) : $id,
            $this->result['inserted_ids'] ?? [],
        );
    }

    public function isAcknowledged(): bool
    {
        return $this->result['acknowledged'] ?? true;
    }
}
