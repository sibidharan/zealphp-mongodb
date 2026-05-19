<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * BSON Timestamp type.
 *
 * Represents a MongoDB internal timestamp used for replication.
 * Not to be confused with UTCDateTime for date/time values.
 *
 * Note: The constructor takes (increment, timestamp) -- increment FIRST,
 * matching the official MongoDB PHP driver convention.
 */
class Timestamp implements TimestampInterface, \JsonSerializable, Type, \Stringable
{
    private int $increment;
    private int $timestamp;

    /**
     * @param int|string $increment The increment component.
     * @param int|string $timestamp The timestamp component (seconds since epoch).
     */
    public function __construct(int|string $increment, int|string $timestamp)
    {
        $this->increment = (int)$increment;
        $this->timestamp = (int)$timestamp;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getIncrement(): int
    {
        return $this->increment;
    }

    public function __toString(): string
    {
        return "[{$this->timestamp}:{$this->increment}]";
    }

    public function jsonSerialize(): mixed
    {
        return [
            '$timestamp' => [
                't' => $this->timestamp,
                'i' => $this->increment,
            ],
        ];
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['increment'], $properties['timestamp']);
    }
}
