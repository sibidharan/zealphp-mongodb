<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

class DeleteResult
{
    public function __construct(private array $result)
    {
    }

    public function getDeletedCount(): int
    {
        return $this->result['deleted_count'] ?? 0;
    }

    public function isAcknowledged(): bool
    {
        return $this->result['acknowledged'] ?? true;
    }
}
