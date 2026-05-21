<?php

/**
 * Polyfill stubs for MongoDB\BSON\* interfaces and classes normally provided by ext-mongodb (C driver).
 * These allow the mongodb/mongodb PHP library to load when only the Rust zealphp_mongodb.so extension is present.
 */

namespace MongoDB\BSON {
    if (! interface_exists('MongoDB\BSON\Type', false)) {
        interface Type
        {
        }
    }

    if (! interface_exists('MongoDB\BSON\Serializable', false)) {
        interface Serializable extends Type
        {
            public function bsonSerialize(): array|\stdClass;
        }
    }

    if (! interface_exists('MongoDB\BSON\Unserializable', false)) {
        interface Unserializable
        {
            public function bsonUnserialize(array $data): void;
        }
    }

    if (! interface_exists('MongoDB\BSON\Persistable', false)) {
        interface Persistable extends Serializable, Unserializable
        {
        }
    }

    if (! interface_exists('MongoDB\BSON\ObjectIdInterface', false)) {
        interface ObjectIdInterface
        {
            public function getTimestamp(): int;

            public function __toString(): string;
        }
    }

    if (! interface_exists('MongoDB\BSON\RegexInterface', false)) {
        interface RegexInterface
        {
            public function getPattern(): string;

            public function getFlags(): string;

            public function __toString(): string;
        }
    }

    if (! interface_exists('MongoDB\BSON\TimestampInterface', false)) {
        interface TimestampInterface
        {
            public function getTimestamp(): int;

            public function getIncrement(): int;

            public function __toString(): string;
        }
    }

    if (! interface_exists('MongoDB\BSON\UTCDateTimeInterface', false)) {
        interface UTCDateTimeInterface
        {
            public function toDateTime(): \DateTimeInterface;

            public function __toString(): string;
        }
    }

    if (! interface_exists('MongoDB\BSON\BinaryInterface', false)) {
        interface BinaryInterface
        {
            public function getData(): string;

            public function getType(): int;

            public function __toString(): string;
        }
    }

    if (! interface_exists('MongoDB\BSON\Decimal128Interface', false)) {
        interface Decimal128Interface
        {
            public function __toString(): string;
        }
    }

    if (! interface_exists('MongoDB\BSON\JavascriptInterface', false)) {
        interface JavascriptInterface
        {
            public function getCode(): string;

            public function getScope(): ?object;

            public function __toString(): string;
        }
    }

    if (! class_exists('MongoDB\BSON\ObjectId', false)) {
        class ObjectId implements ObjectIdInterface, \JsonSerializable, Type, Serializable
        {
            private string $oid;

            public function __construct(?string $id = null)
            {
                $this->oid = $id ?? bin2hex(random_bytes(12));
            }

            public function getTimestamp(): int
            {
                return (int) hexdec(substr($this->oid, 0, 8));
            }

            public function __toString(): string
            {
                return $this->oid;
            }

            public function jsonSerialize(): mixed
            {
                return ['$oid' => $this->oid];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$oid' => $this->oid];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\Regex', false)) {
        class Regex implements RegexInterface, \JsonSerializable, Type, Serializable
        {
            public function __construct(private string $pattern, private string $flags = '')
            {
            }

            public function getPattern(): string
            {
                return $this->pattern;
            }

            public function getFlags(): string
            {
                return $this->flags;
            }

            public function __toString(): string
            {
                return '/' . $this->pattern . '/' . $this->flags;
            }

            public function jsonSerialize(): mixed
            {
                return ['$regex' => $this->pattern, '$options' => $this->flags];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$regex' => $this->pattern, '$options' => $this->flags];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\UTCDateTime', false)) {
        class UTCDateTime implements UTCDateTimeInterface, \JsonSerializable, Type, Serializable
        {
            private int $milliseconds;

            public function __construct(int|float|string|\DateTimeInterface|null $milliseconds = null)
            {
                if ($milliseconds instanceof \DateTimeInterface) {
                    $this->milliseconds = (int) ($milliseconds->format('Uv'));
                } elseif ($milliseconds === null) {
                    $this->milliseconds = (int) (microtime(true) * 1000);
                } else {
                    $this->milliseconds = (int) $milliseconds;
                }
            }

            public function toDateTime(): \DateTimeInterface
            {
                $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.3f', $this->milliseconds / 1000));

                return $dt ?: new \DateTimeImmutable();
            }

            public function __toString(): string
            {
                return (string) $this->milliseconds;
            }

            public function jsonSerialize(): mixed
            {
                return ['$date' => ['$numberLong' => (string) $this->milliseconds]];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$date' => ['$numberLong' => (string) $this->milliseconds]];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\Binary', false)) {
        class Binary implements BinaryInterface, \JsonSerializable, Type, Serializable
        {
            public const TYPE_GENERIC = 0;
            public const TYPE_FUNCTION = 1;
            public const TYPE_OLD_BINARY = 2;
            public const TYPE_OLD_UUID = 3;
            public const TYPE_UUID = 4;
            public const TYPE_MD5 = 5;
            public const TYPE_ENCRYPTED = 6;
            public const TYPE_COLUMN = 7;
            public const TYPE_USER_DEFINED = 128;

            public function __construct(private string $data, private int $type = self::TYPE_GENERIC)
            {
            }

            public function getData(): string
            {
                return $this->data;
            }

            public function getType(): int
            {
                return $this->type;
            }

            public function __toString(): string
            {
                return $this->data;
            }

            public function jsonSerialize(): mixed
            {
                return ['$binary' => ['base64' => base64_encode($this->data), 'subType' => sprintf('%02x', $this->type)]];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$binary' => ['base64' => base64_encode($this->data), 'subType' => sprintf('%02x', $this->type)]];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\Timestamp', false)) {
        class Timestamp implements TimestampInterface, \JsonSerializable, Type, Serializable
        {
            public function __construct(private int $increment, private int $timestamp)
            {
            }

            public function getTimestamp(): int
            {
                return $this->timestamp;
            }

            public function getIncrement(): int
            {
                return $this->increment;
            }

            public function __toString(): string
            {
                return sprintf('[%d:%d]', $this->timestamp, $this->increment);
            }

            public function jsonSerialize(): mixed
            {
                return ['$timestamp' => ['t' => $this->timestamp, 'i' => $this->increment]];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$timestamp' => ['t' => $this->timestamp, 'i' => $this->increment]];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\Decimal128', false)) {
        class Decimal128 implements Decimal128Interface, \JsonSerializable, Type, Serializable
        {
            public function __construct(private string $value)
            {
            }

            public function __toString(): string
            {
                return $this->value;
            }

            public function jsonSerialize(): mixed
            {
                return ['$numberDecimal' => $this->value];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$numberDecimal' => $this->value];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\Javascript', false)) {
        class Javascript implements JavascriptInterface, \JsonSerializable, Type, Serializable
        {
            public function __construct(private string $code, private ?object $scope = null)
            {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function getScope(): ?object
            {
                return $this->scope;
            }

            public function __toString(): string
            {
                return $this->code;
            }

            public function jsonSerialize(): mixed
            {
                $result = ['$code' => $this->code];
                if ($this->scope !== null) {
                    $result['$scope'] = $this->scope;
                }

                return $result;
            }

            public function bsonSerialize(): array|\stdClass
            {
                return (array) $this->jsonSerialize();
            }
        }
    }

    if (! class_exists('MongoDB\BSON\MaxKey', false)) {
        class MaxKey implements Type, Serializable, \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['$maxKey' => 1];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$maxKey' => 1];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\MinKey', false)) {
        class MinKey implements Type, Serializable, \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['$minKey' => 1];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$minKey' => 1];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\Int64', false)) {
        class Int64 implements \JsonSerializable, Type, Serializable
        {
            public function __construct(private int|string $value)
            {
            }

            public function __toString(): string
            {
                return (string) $this->value;
            }

            public function jsonSerialize(): mixed
            {
                return ['$numberLong' => (string) $this->value];
            }

            public function bsonSerialize(): array|\stdClass
            {
                return ['$numberLong' => (string) $this->value];
            }
        }
    }

    if (! class_exists('MongoDB\BSON\Document', false)) {
        class Document implements \IteratorAggregate, \Countable
        {
            private array $data;

            private function __construct(array $data)
            {
                $this->data = $data;
            }

            public static function fromPHP(array|object $value): self
            {
                return new self((array) $value);
            }

            public static function fromJSON(string $json): self
            {
                return new self(json_decode($json, true) ?? []);
            }

            public static function fromBSON(string $bson): self
            {
                return new self([]);
            }

            public function toPHP(?array $typeMap = null): object|array
            {
                if (($typeMap['root'] ?? null) === 'array') {
                    return $this->data;
                }

                return (object) $this->data;
            }

            public function toCanonicalExtendedJSON(): string
            {
                return json_encode($this->data, JSON_THROW_ON_ERROR);
            }

            public function toRelaxedExtendedJSON(): string
            {
                return json_encode($this->data, JSON_THROW_ON_ERROR);
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function getIterator(): \ArrayIterator
            {
                return new \ArrayIterator($this->data);
            }

            public function count(): int
            {
                return count($this->data);
            }
        }
    }

    if (! class_exists('MongoDB\BSON\PackedArray', false)) {
        class PackedArray implements \IteratorAggregate, \Countable
        {
            private array $data;

            private function __construct(array $data)
            {
                $this->data = array_values($data);
            }

            public static function fromPHP(array $value): self
            {
                return new self($value);
            }

            public function toPHP(?array $typeMap = null): array
            {
                return $this->data;
            }

            public function getIterator(): \ArrayIterator
            {
                return new \ArrayIterator($this->data);
            }

            public function count(): int
            {
                return count($this->data);
            }
        }
    }
}

namespace MongoDB {
    if (! function_exists('MongoDB\recursive_copy')) {
        function recursive_copy(mixed $element): mixed
        {
            if (is_array($element)) {
                return array_map('MongoDB\recursive_copy', $element);
            }

            if (is_object($element)) {
                return clone $element;
            }

            return $element;
        }
    }
}

namespace MongoDB\Operation {
    if (! class_exists('MongoDB\Operation\FindOneAndUpdate', false)) {
        class FindOneAndUpdate
        {
            public const RETURN_DOCUMENT_BEFORE = 1;
            public const RETURN_DOCUMENT_AFTER = 2;
        }
    }

    if (! class_exists('MongoDB\Operation\FindOneAndReplace', false)) {
        class FindOneAndReplace
        {
            public const RETURN_DOCUMENT_BEFORE = 1;
            public const RETURN_DOCUMENT_AFTER = 2;
        }
    }
}

namespace MongoDB\Driver {
    if (! class_exists('MongoDB\Driver\WriteConcern', false)) {
        class WriteConcern
        {
            public const MAJORITY = 'majority';

            private string|int $w;
            private int $wtimeout;

            public function __construct(string|int $w = 1, int $wtimeout = 0)
            {
                $this->w = $w;
                $this->wtimeout = $wtimeout;
            }

            public function getW(): string|int
            {
                return $this->w;
            }

            public function getWtimeout(): int
            {
                return $this->wtimeout;
            }
        }
    }
}

namespace MongoDB\Driver\Exception {
    if (! class_exists('MongoDB\Driver\Exception\Exception', false)) {
        interface Exception extends \Throwable {}
    }

    if (! class_exists('MongoDB\Driver\Exception\RuntimeException', false)) {
        class RuntimeException extends \RuntimeException implements Exception {}
    }

    if (! class_exists('MongoDB\Driver\Exception\BulkWriteException', false)) {
        class BulkWriteException extends RuntimeException {}
    }

    if (! class_exists('MongoDB\Driver\Exception\ConnectionException', false)) {
        class ConnectionException extends RuntimeException {}
    }

    if (! class_exists('MongoDB\Driver\Exception\AuthenticationException', false)) {
        class AuthenticationException extends ConnectionException {}
    }

    if (! class_exists('MongoDB\Driver\Exception\ConnectionTimeoutException', false)) {
        class ConnectionTimeoutException extends ConnectionException {}
    }

    if (! class_exists('MongoDB\Driver\Exception\ServerException', false)) {
        class ServerException extends RuntimeException {}
    }

    if (! class_exists('MongoDB\Driver\Exception\CommandException', false)) {
        class CommandException extends ServerException {}
    }

    if (! class_exists('MongoDB\Driver\Exception\ExecutionTimeoutException', false)) {
        class ExecutionTimeoutException extends ServerException {}
    }
}
