<?php
namespace ZealPHP\MongoDB\BSON;

class Regex implements \Stringable, \JsonSerializable
{
    public function __construct(private string $pattern, private string $flags = '') {}
    public function getPattern(): string { return $this->pattern; }
    public function getFlags(): string { return $this->flags; }
    public function __toString(): string { return "/{$this->pattern}/{$this->flags}"; }
    public function jsonSerialize(): mixed { return ['$regex' => $this->pattern, '$options' => $this->flags]; }
}
