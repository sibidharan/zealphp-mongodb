<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

use JsonSerializable;
use Stringable;

/**
 * BSON Regex type.
 *
 * Represents a regular expression pattern and optional flags.
 */
class Regex implements RegexInterface, JsonSerializable, Type, Stringable
{
    public function __construct(private readonly string $pattern, private readonly string $flags = '')
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
        return "/{$this->pattern}/{$this->flags}";
    }

    public function jsonSerialize(): mixed
    {
        return ['$regex' => $this->pattern, '$options' => $this->flags];
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['pattern'], $properties['flags']);
    }
}
