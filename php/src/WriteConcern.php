<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use JsonSerializable;
use stdClass;

use function array_filter;

class WriteConcern implements JsonSerializable
{
    public const MAJORITY = 'majority';
    public readonly int $wtimeout;

    public function __construct(public readonly string|int|null $w, int|null $wtimeout = null, public readonly bool|null $j = null)
    {
        $this->wtimeout = $wtimeout ?? 0;
    }

    public function getW(): string|int|null
    {
        return $this->w;
    }

    public function getJournal(): bool|null
    {
        return $this->j;
    }

    public function getWtimeout(): int
    {
        return $this->wtimeout;
    }

    public function isDefault(): bool
    {
        return $this->w === null && $this->j === null && $this->wtimeout === 0;
    }

    public function jsonSerialize(): mixed
    {
        return ['w' => $this->w, 'j' => $this->j, 'wtimeout' => $this->wtimeout];
    }

    public function bsonSerialize(): stdClass
    {
        return (object) array_filter(['w' => $this->w, 'j' => $this->j, 'wtimeout' => $this->wtimeout], static fn ($v) => $v !== null);
    }
}
