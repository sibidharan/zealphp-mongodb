<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

use stdClass;

/**
 * Classes implementing this interface may return data to be serialized
 * as a BSON array or document in lieu of the object's public properties.
 */
interface Serializable extends Type
{
    /**
     * Provides an array or document to serialize as BSON.
     */
    public function bsonSerialize(): array|stdClass;
}
