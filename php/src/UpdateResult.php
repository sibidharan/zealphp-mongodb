<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

class UpdateResult
{
    public function __construct(private array $result)
    {
    }

    public function getMatchedCount(): int
    {
        return $this->result['matched_count'] ?? 0;
    }

    public function getModifiedCount(): int
    {
        return $this->result['modified_count'] ?? 0;
    }

    public function getUpsertedCount(): int
    {
        return $this->result['upserted_count'] ?? 0;
    }

    public function getUpsertedId(): mixed
    {
        return $this->result['upserted_id'] ?? null;
    }

    public function isAcknowledged(): bool
    {
        return $this->result['acknowledged'] ?? true;
    }
}
