<?php
namespace ZealPHP\MongoDB;

class ReadPreference implements \JsonSerializable
{
    const PRIMARY = 'primary';
    const PRIMARY_PREFERRED = 'primaryPreferred';
    const SECONDARY = 'secondary';
    const SECONDARY_PREFERRED = 'secondaryPreferred';
    const NEAREST = 'nearest';
    const NO_MAX_STALENESS = -1;
    const SMALLEST_MAX_STALENESS_SECONDS = 90;

    public readonly string $mode;
    public readonly ?array $tags;
    public readonly int $maxStalenessSeconds;

    public function __construct(string $mode, ?array $tagSets = null, ?array $options = null)
    {
        $this->mode = $mode;
        $this->tags = $tagSets;
        $this->maxStalenessSeconds = $options['maxStalenessSeconds'] ?? self::NO_MAX_STALENESS;
    }

    public function getModeString(): string { return $this->mode; }
    public function getTagSets(): array { return $this->tags ?? []; }
    public function getMaxStalenessSeconds(): int { return $this->maxStalenessSeconds; }
    public function jsonSerialize(): mixed { return ['mode' => $this->mode]; }
    public function bsonSerialize(): \stdClass { return (object)['mode' => $this->mode]; }
}
