<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * BSON UTCDateTime type.
 *
 * Represents a UTC date and time value with millisecond precision.
 */
class UTCDateTime implements UTCDateTimeInterface, \JsonSerializable, Type, \Stringable
{
    private int $milliseconds;

    /**
     * @param int|float|string|\DateTimeInterface|null $milliseconds Milliseconds since epoch,
     *        a DateTimeInterface, or null for current time.
     */
    public function __construct(int|float|string|\DateTimeInterface|null $milliseconds = null)
    {
        if ($milliseconds instanceof \DateTimeInterface) {
            // Convert DateTimeInterface to milliseconds
            $this->milliseconds = (int)($milliseconds->format('U') * 1000
                + (int)$milliseconds->format('v'));
        } elseif ($milliseconds !== null) {
            $this->milliseconds = (int)$milliseconds;
        } else {
            $this->milliseconds = (int)(microtime(true) * 1000);
        }
    }

    /**
     * Returns a mutable DateTime representation.
     */
    public function toDateTime(): \DateTime
    {
        $dt = \DateTime::createFromFormat('U.u', sprintf('%.3f', $this->milliseconds / 1000));
        if ($dt === false) {
            // Fallback for edge cases
            $dt = new \DateTime();
            $dt->setTimestamp((int)($this->milliseconds / 1000));
        }
        return $dt;
    }

    /**
     * Returns an immutable DateTimeImmutable representation.
     */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.3f', $this->milliseconds / 1000));
        if ($dt === false) {
            $dt = new \DateTimeImmutable();
            $dt = $dt->setTimestamp((int)($this->milliseconds / 1000));
        }
        return $dt;
    }

    public function __toString(): string
    {
        return (string)$this->milliseconds;
    }

    public function jsonSerialize(): mixed
    {
        return ['$date' => ['$numberLong' => (string)$this->milliseconds]];
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['milliseconds']);
    }
}
