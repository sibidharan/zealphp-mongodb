<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

use function bin2hex;
use function dechex;
use function hexdec;
use function preg_match;
use function random_bytes;
use function str_pad;
use function strtolower;
use function substr;
use function time;

use const STR_PAD_LEFT;

/**
 * BSON ObjectId type.
 *
 * Represents a 12-byte identifier, typically used as a unique identifier
 * for documents in a MongoDB collection.
 */
class ObjectId implements ObjectIdInterface, JsonSerializable, Type, Stringable
{
    private string $id;

    /**
     * @param string|DateTimeInterface|null $id A 24-character hexadecimal string,
     *        a DateTimeInterface (uses its timestamp for the first 4 bytes), or null to generate.
     *
     * @throws InvalidArgumentException if the string is not a valid 24-hex-char ObjectId
     */
    public function __construct(string|DateTimeInterface|null $id = null)
    {
        if ($id instanceof DateTimeInterface) {
            // Use the DateTime's timestamp for the first 4 bytes, random for the rest
            $timestamp = dechex($id->getTimestamp());
            $timestamp = str_pad($timestamp, 8, '0', STR_PAD_LEFT);
            $this->id = $timestamp . bin2hex(random_bytes(8));
        } elseif ($id !== null) {
            if (! preg_match('/^[0-9a-fA-F]{24}$/', $id)) {
                throw new InvalidArgumentException(
                    "Error parsing ObjectId string: expected 24 hex characters, got \"$id\"",
                );
            }

            $this->id = strtolower($id);
        } else {
            // Generate: 4-byte timestamp + 8 random bytes = 24 hex chars
            $timestamp = dechex(time());
            $timestamp = str_pad($timestamp, 8, '0', STR_PAD_LEFT);
            $this->id = $timestamp . bin2hex(random_bytes(8));
        }
    }

    public function __toString(): string
    {
        return $this->id;
    }

    public function getTimestamp(): int
    {
        return hexdec(substr($this->id, 0, 8));
    }

    public function jsonSerialize(): mixed
    {
        return ['$oid' => $this->id];
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['id']);
    }
}
