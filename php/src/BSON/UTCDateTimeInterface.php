<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * Interface for BSON UTCDateTime type.
 */
interface UTCDateTimeInterface
{
    /**
     * Returns the DateTime representation of this UTCDateTime.
     */
    public function toDateTime(): \DateTime;

    /**
     * Returns the DateTimeImmutable representation of this UTCDateTime.
     */
    public function toDateTimeImmutable(): \DateTimeImmutable;

    /**
     * Returns the string representation of this UTCDateTime.
     */
    public function __toString(): string;
}
