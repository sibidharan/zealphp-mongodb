<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * BSON Decimal128 type.
 *
 * Represents a 128-bit decimal floating point value, suitable for
 * storing exact decimal representations (e.g., financial data).
 */
class Decimal128 implements Decimal128Interface, \JsonSerializable, Type, \Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): mixed
    {
        return ['$numberDecimal' => $this->value];
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['value']);
    }
}
