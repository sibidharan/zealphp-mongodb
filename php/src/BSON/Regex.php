<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * BSON Regex type.
 *
 * Represents a regular expression pattern and optional flags.
 */
class Regex implements RegexInterface, \JsonSerializable, Type, \Stringable
{
    private string $pattern;
    private string $flags;

    public function __construct(string $pattern, string $flags = '')
    {
        $this->pattern = $pattern;
        $this->flags = $flags;
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
