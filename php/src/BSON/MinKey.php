<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * BSON MinKey type.
 *
 * Represents the BSON MinKey value, which compares less than
 * all other possible BSON element values.
 */
class MinKey implements \JsonSerializable, Type
{
    public function jsonSerialize(): mixed
    {
        return ['$minKey' => 1];
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self();
    }
}
