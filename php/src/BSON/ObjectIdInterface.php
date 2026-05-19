<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * Interface for BSON ObjectId type.
 */
interface ObjectIdInterface
{
    /**
     * Returns the timestamp component of this ObjectId.
     */
    public function getTimestamp(): int;

    /**
     * Returns the hexadecimal representation of this ObjectId.
     */
    public function __toString(): string;
}
