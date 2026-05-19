<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

use JsonSerializable;
use Stringable;

use function is_array;

/**
 * BSON Javascript type.
 *
 * Represents a JavaScript code string, optionally with a scope document.
 */
class Javascript implements JavascriptInterface, JsonSerializable, Type, Stringable
{
    private object|null $scope;

    /**
     * @param string $code JavaScript code string.
     * @param array|object|null $scope Optional scope document for the code.
     */
    public function __construct(private readonly string $code, array|object|null $scope = null)
    {
        if (is_array($scope)) {
            $this->scope = (object) $scope;
        } else {
            $this->scope = $scope;
        }
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getScope(): object|null
    {
        return $this->scope;
    }

    public function __toString(): string
    {
        return $this->code;
    }

    public function jsonSerialize(): mixed
    {
        $result = ['$code' => $this->code];
        if ($this->scope !== null) {
            $result['$scope'] = $this->scope;
        }

        return $result;
    }

    /**
     * Creates a new instance from var_export() output.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['code'], $properties['scope'] ?? null);
    }
}
