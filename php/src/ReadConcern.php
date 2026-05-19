<?php
namespace ZealPHP\MongoDB;

class ReadConcern implements \JsonSerializable
{
    const LINEARIZABLE = 'linearizable';
    const LOCAL = 'local';
    const MAJORITY = 'majority';
    const AVAILABLE = 'available';
    const SNAPSHOT = 'snapshot';

    public readonly ?string $level;

    public function __construct(?string $level = null) { $this->level = $level; }
    public function getLevel(): ?string { return $this->level; }
    public function isDefault(): bool { return $this->level === null; }
    public function jsonSerialize(): mixed { return $this->level ? ['level' => $this->level] : new \stdClass(); }
    public function bsonSerialize(): \stdClass { return (object)($this->level ? ['level' => $this->level] : []); }
}
