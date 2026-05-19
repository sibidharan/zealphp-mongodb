<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * Classes implementing this interface may be populated from BSON data
 * during unserialization.
 */
interface Unserializable
{
    /**
     * Constructs the object from a BSON array or document.
     */
    public function bsonUnserialize(array $data): void;
}
