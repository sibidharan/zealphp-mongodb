<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use JsonSerializable;
use stdClass;
use ZealPHP\MongoDB\Exception\InvalidArgumentException;

use function in_array;
use function sprintf;

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
        // Eager client-side validation, matching MongoDB\Driver\ReadPreference (#68).
        if (! in_array($mode, [self::PRIMARY, self::PRIMARY_PREFERRED, self::SECONDARY, self::SECONDARY_PREFERRED, self::NEAREST], true)) {
            throw new InvalidArgumentException(sprintf('Invalid mode: "%s"', $mode));
        }

        $maxStaleness = $options['maxStalenessSeconds'] ?? self::NO_MAX_STALENESS;

        if ($mode === self::PRIMARY && $tags !== null && $tags !== []) {
            throw new InvalidArgumentException('tagSets may not be used with primary mode');
        }

        if ($mode === self::PRIMARY && $maxStaleness !== self::NO_MAX_STALENESS) {
            throw new InvalidArgumentException('maxStalenessSeconds may not be used with primary mode');
        }

        if ($maxStaleness !== self::NO_MAX_STALENESS && $maxStaleness < self::SMALLEST_MAX_STALENESS_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'Expected maxStalenessSeconds to be >= %d, %d given',
                self::SMALLEST_MAX_STALENESS_SECONDS,
                $maxStaleness,
            ));
        }

        $this->maxStalenessSeconds = $maxStaleness;
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
