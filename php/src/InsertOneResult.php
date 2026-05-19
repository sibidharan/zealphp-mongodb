<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use function is_array;

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
        $id = $this->result['inserted_id'] ?? null;

        return is_array($id) ? Collection::wrapDoc($id) : $id;
    }

    public function isAcknowledged(): bool
    {
        return $this->result['acknowledged'] ?? true;
    }
}
