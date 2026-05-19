<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

/**
 * Interface for BSON Timestamp type.
 */
interface TimestampInterface
{
    /**
     * Returns the timestamp component of this Timestamp.
     */
    public function getTimestamp(): int;

    /**
     * Returns the increment component of this Timestamp.
     */
    public function getIncrement(): int;

    /**
     * Returns the string representation of this Timestamp.
     */
    public function __toString(): string;
}
