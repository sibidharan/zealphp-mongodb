<?php
namespace ZealPHP\MongoDB\BSON;

/**
 * BSON Javascript type.
 *
 * Represents a JavaScript code string, optionally with a scope document.
 */
class Javascript implements JavascriptInterface, \JsonSerializable, Type, \Stringable
{
    private string $code;
    private ?object $scope;

    /**
     * @param string $code JavaScript code string.
     * @param array|object|null $scope Optional scope document for the code.
     */
    public function __construct(string $code, array|object|null $scope = null)
    {
        $this->code = $code;
        if (is_array($scope)) {
            $this->scope = (object)$scope;
        } else {
            $this->scope = $scope;
        }
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getScope(): ?object
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
