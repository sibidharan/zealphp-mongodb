<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

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
        return $this->result['inserted_ids'] ?? [];
    }

    public function isAcknowledged(): bool
    {
        return $this->result['acknowledged'] ?? true;
    }
}
