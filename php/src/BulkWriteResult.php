<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

class BulkWriteResult
{
    public function __construct(private array $result)
    {
    }

    public function getInsertedCount(): int
    {
        return $this->result['inserted_count'] ?? 0;
    }

    public function getMatchedCount(): int
    {
        return $this->result['matched_count'] ?? 0;
    }

    public function getModifiedCount(): int
    {
        return $this->result['modified_count'] ?? 0;
    }

    public function getDeletedCount(): int
    {
        return $this->result['deleted_count'] ?? 0;
    }

    public function getUpsertedCount(): int
    {
        return $this->result['upserted_count'] ?? 0;
    }

    public function getUpsertedIds(): array
    {
        return $this->result['upserted_ids'] ?? [];
    }

    public function isAcknowledged(): bool
    {
        return $this->result['acknowledged'] ?? true;
    }
}
