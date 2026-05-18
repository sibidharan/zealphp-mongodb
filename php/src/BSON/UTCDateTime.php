<?php
namespace ZealPHP\MongoDB\BSON;

class UTCDateTime implements \Stringable, \JsonSerializable
{
    private int $milliseconds;

    public function __construct(int|string|null $milliseconds = null)
    {
        $this->milliseconds = $milliseconds !== null ? (int)$milliseconds : (int)(microtime(true) * 1000);
    }

    public function toDateTime(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.3f', $this->milliseconds / 1000));
    }

    public function __toString(): string { return (string)$this->milliseconds; }
    public function jsonSerialize(): mixed { return ['$date' => ['$numberLong' => (string)$this->milliseconds]]; }
}
