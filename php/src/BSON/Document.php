<?php
namespace ZealPHP\MongoDB\BSON;

use LogicException;

/**
 * BSON Document type.
 *
 * Represents an immutable BSON document. Created via static factories.
 * Provides array-like access (read-only) and JSON serialization.
 */
class Document implements \IteratorAggregate, \ArrayAccess, Type, \Stringable, \Countable
{
    private array $data;

    /**
     * Private constructor -- use fromPHP() or fromJSON().
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Creates a Document from a PHP array or object.
     */
    public static function fromPHP(array|object $value): self
    {
        if (is_object($value)) {
            $value = (array)$value;
        }
        return new self($value);
    }

    /**
     * Creates a Document from a JSON string.
     *
     * @throws \InvalidArgumentException if the JSON is invalid
     */
    public static function fromJSON(string $json): self
    {
        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                'Error decoding JSON: ' . json_last_error_msg()
            );
        }
        return new self($data ?? []);
    }

    /**
     * Returns the value for a given key.
     *
     * @throws \OutOfRangeException if the key does not exist
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            throw new \OutOfRangeException("Key \"$key\" not found in document");
        }
        return $this->data[$key];
    }

    /**
     * Returns whether the given key exists in this document.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Returns the document as a PHP object (stdClass).
     */
    public function toPHP(): object
    {
        return (object)$this->data;
    }

    /**
     * Returns the canonical extended JSON representation.
     */
    public function toCanonicalExtendedJSON(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns the relaxed extended JSON representation.
     */
    public function toRelaxedExtendedJSON(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function __toString(): string
    {
        return $this->toCanonicalExtendedJSON();
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
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
        throw new LogicException('BSON Document is immutable; offsetSet is not supported');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('BSON Document is immutable; offsetUnset is not supported');
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['data']);
    }
}
