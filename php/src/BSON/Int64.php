<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

use JsonSerializable;
use Stringable;

/**
 * BSON Int64 type.
 *
 * Represents a 64-bit integer. On 64-bit platforms PHP natively handles
 * 64-bit integers, but this class allows explicit typing for BSON serialization.
 */
class Int64 implements JsonSerializable, Type, Stringable
{
    private readonly int $value;

    /** @param int|string $value A 64-bit integer value. */
    public function __construct(int|string $value)
    {
        $this->value = (int) $value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function jsonSerialize(): mixed
    {
        return $this->value;
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['value']);
    }
}
