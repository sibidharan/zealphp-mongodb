<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

use DateTime;
use DateTimeImmutable;

/**
 * Interface for BSON UTCDateTime type.
 */
interface UTCDateTimeInterface
{
    /**
     * Returns the DateTime representation of this UTCDateTime.
     */
    public function toDateTime(): DateTime;

    /**
     * Returns the DateTimeImmutable representation of this UTCDateTime.
     */
    public function toDateTimeImmutable(): DateTimeImmutable;

    /**
     * Returns the string representation of this UTCDateTime.
     */
    public function __toString(): string;
}
