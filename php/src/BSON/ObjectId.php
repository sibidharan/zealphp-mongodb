<?php
namespace ZealPHP\MongoDB\BSON;

class ObjectId implements \Stringable, \JsonSerializable
{
    private string $id;

    public function __construct(?string $id = null)
    {
        $this->id = $id ?? bin2hex(random_bytes(12));
    }

    public function __toString(): string { return $this->id; }
    public function getTimestamp(): int { return hexdec(substr($this->id, 0, 8)); }
    public function jsonSerialize(): mixed { return ['$oid' => $this->id]; }
}
