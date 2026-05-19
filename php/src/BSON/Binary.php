<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

use JsonSerializable;
use Stringable;

use function base64_encode;
use function dechex;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * BSON Binary type.
 *
 * Represents arbitrary binary data with an associated subtype.
 */
class Binary implements BinaryInterface, JsonSerializable, Type, Stringable
{
    public const TYPE_GENERIC      = 0;
    public const TYPE_FUNCTION     = 1;
    public const TYPE_OLD_BINARY   = 2;
    public const TYPE_OLD_UUID     = 3;
    public const TYPE_UUID         = 4;
    public const TYPE_MD5          = 5;
    public const TYPE_ENCRYPTED    = 6;
    public const TYPE_COLUMN       = 7;
    public const TYPE_SENSITIVE    = 8;
    public const TYPE_VECTOR       = 9;
    public const TYPE_USER_DEFINED = 128;

    public function __construct(private readonly string $data, private readonly int $type = self::TYPE_GENERIC)
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
        return [
            '$binary' => [
                'base64'  => base64_encode($this->data),
                'subType' => str_pad(dechex($this->type), 2, '0', STR_PAD_LEFT),
            ],
        ];
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['data'], $properties['type']);
    }
}
