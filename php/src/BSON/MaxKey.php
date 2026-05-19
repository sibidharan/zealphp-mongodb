<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * BSON MaxKey type.
 *
 * Represents the BSON MaxKey value, which compares greater than
 * all other possible BSON element values.
 */
class MaxKey implements \JsonSerializable, Type
{
    public function jsonSerialize(): mixed
    {
        return ['$maxKey' => 1];
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self();
    }
}
