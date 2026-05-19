<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use JsonSerializable;
use stdClass;

class ReadConcern implements JsonSerializable
{
    public const LINEARIZABLE = 'linearizable';
    public const LOCAL = 'local';
    public const MAJORITY = 'majority';
    public const AVAILABLE = 'available';
    public const SNAPSHOT = 'snapshot';

    public function __construct(public readonly string|null $level = null)
    {
    }

    public function getLevel(): string|null
    {
        return $this->level;
    }

    public function isDefault(): bool
    {
        return $this->level === null;
    }

    public function jsonSerialize(): mixed
    {
        return $this->level ? ['level' => $this->level] : new stdClass();
    }

    public function bsonSerialize(): stdClass
    {
        return (object) ($this->level ? ['level' => $this->level] : []);
    }
}
