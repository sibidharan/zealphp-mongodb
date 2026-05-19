<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

/**
 * Classes may implement this interface to take advantage of automatic
 * ODM (Object Document Mapping) behavior in the driver.
 *
 * During serialization, the driver will inject a __pclass property
 * containing the PHP class name into the data returned by bsonSerialize().
 *
 * During unserialization, the driver will use the __pclass property
 * to determine the PHP class and call bsonUnserialize().
 */
interface Persistable extends Serializable, Unserializable
{
}
