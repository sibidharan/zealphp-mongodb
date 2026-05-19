<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use JsonSerializable;
use stdClass;

class ReadPreference implements JsonSerializable
{
    public const PRIMARY = 'primary';
    public const PRIMARY_PREFERRED = 'primaryPreferred';
    public const SECONDARY = 'secondary';
    public const SECONDARY_PREFERRED = 'secondaryPreferred';
    public const NEAREST = 'nearest';
    public const NO_MAX_STALENESS = -1;
    public const SMALLEST_MAX_STALENESS_SECONDS = 90;
    public readonly int $maxStalenessSeconds;

    public function __construct(public readonly string $mode, public readonly array|null $tags = null, array|null $options = null)
    {
        $this->maxStalenessSeconds = $options['maxStalenessSeconds'] ?? self::NO_MAX_STALENESS;
    }

    public function getModeString(): string
    {
        return $this->mode;
    }

    public function getTagSets(): array
    {
        return $this->tags ?? [];
    }

    public function getMaxStalenessSeconds(): int
    {
        return $this->maxStalenessSeconds;
    }

    public function jsonSerialize(): mixed
    {
        return ['mode' => $this->mode];
    }

    public function bsonSerialize(): stdClass
    {
        return (object) ['mode' => $this->mode];
    }
}
