<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * Classes implementing this interface may return data to be serialized
 * as a BSON array or document in lieu of the object's public properties.
 */
interface Serializable extends Type
{
    /**
     * Provides an array or document to serialize as BSON.
     */
    public function bsonSerialize(): array|\stdClass;
}
