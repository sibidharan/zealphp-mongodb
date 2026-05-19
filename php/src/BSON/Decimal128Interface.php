<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * Interface for BSON Decimal128 type.
 */
interface Decimal128Interface
{
    /**
     * Returns the string representation of this Decimal128.
     */
    public function __toString(): string;
}
