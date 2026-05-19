<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

class InsertOneResult
{
    public function __construct(private array $result)
    {
    }

    public function getInsertedCount(): int
    {
        return $this->result['inserted_count'] ?? 0;
    }

    public function getInsertedId(): mixed
    {
        return $this->result['inserted_id'] ?? null;
    }

    public function isAcknowledged(): bool
    {
        return $this->result['acknowledged'] ?? true;
    }
}
