<?php
namespace ZealPHP\MongoDB;

class WriteConcern implements \JsonSerializable
{
    const MAJORITY = 'majority';

    public readonly string|int|null $w;
    public readonly ?bool $j;
    public readonly int $wtimeout;

    public function __construct(string|int $w, ?int $wtimeout = null, ?bool $journal = null)
    {
        $this->w = $w;
        $this->wtimeout = $wtimeout ?? 0;
        $this->j = $journal;
    }

    public function getW(): string|int|null { return $this->w; }
    public function getJournal(): ?bool { return $this->j; }
    public function getWtimeout(): int { return $this->wtimeout; }
    public function isDefault(): bool { return $this->w === null && $this->j === null && $this->wtimeout === 0; }
    public function jsonSerialize(): mixed { return ['w' => $this->w, 'j' => $this->j, 'wtimeout' => $this->wtimeout]; }
    public function bsonSerialize(): \stdClass { return (object)array_filter(['w' => $this->w, 'j' => $this->j, 'wtimeout' => $this->wtimeout], fn($v) => $v !== null); }
}
