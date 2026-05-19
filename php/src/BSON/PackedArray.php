<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

use ArrayAccess;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use LogicException;
use OutOfRangeException;
use Stringable;

use function array_is_list;
use function array_key_exists;
use function array_values;
use function count;
use function is_array;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;

use const JSON_ERROR_NONE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * BSON PackedArray type.
 *
 * Represents an immutable BSON array (sequential, integer-indexed).
 * Created via static factories.
 */
class PackedArray implements IteratorAggregate, ArrayAccess, Type, Stringable, Countable
{
    /** @var list<mixed> */
    private array $data;

    /**
     * Private constructor -- use fromPHP() or fromJSON().
     */
    private function __construct(array $data)
    {
        // Re-index to ensure sequential 0-based keys
        $this->data = array_values($data);
    }

    /**
     * Creates a PackedArray from a PHP array.
     */
    public static function fromPHP(array $value): self
    {
        return new self($value);
    }

    /**
     * Creates a PackedArray from a JSON string.
     *
     * @throws InvalidArgumentException if the JSON is invalid or not an array
     */
    public static function fromJSON(string $json): self
    {
        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Error decoding JSON: ' . json_last_error_msg(),
            );
        }

        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidArgumentException('JSON does not represent a sequential array');
        }

        return new self($data);
    }

    /**
     * Returns the value at a given integer index.
     *
     * @throws OutOfRangeException if the index does not exist
     */
    public function get(int $index): mixed
    {
        if (! array_key_exists($index, $this->data)) {
            throw new OutOfRangeException("Index $index not found in packed array");
        }

        return $this->data[$index];
    }

    /**
     * Returns whether the given index exists.
     */
    public function has(int $index): bool
    {
        return array_key_exists($index, $this->data);
    }

    /**
     * Returns the array as a PHP array.
     */
    public function toPHP(): array
    {
        return $this->data;
    }

    /**
     * Returns the canonical extended JSON representation.
     */
    public function toCanonicalExtendedJSON(): string
    {
        return (string) json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns the relaxed extended JSON representation.
     */
    public function toRelaxedExtendedJSON(): string
    {
        return (string) json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function __toString(): string
    {
        return $this->toCanonicalExtendedJSON();
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->data);
    }

    // ArrayAccess (read-only)

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('BSON PackedArray is immutable; offsetSet is not supported');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('BSON PackedArray is immutable; offsetUnset is not supported');
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['data']);
    }
}
