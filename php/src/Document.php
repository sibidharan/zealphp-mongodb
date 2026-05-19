<?php
namespace ZealPHP\MongoDB;

class Document extends \ArrayObject implements \JsonSerializable
{
    public function __construct(array $data = [])
    {
        parent::__construct($data, \ArrayObject::ARRAY_AS_PROPS);
    }

    public function getArrayCopy(): array
    {
        $result = [];
        foreach (parent::getArrayCopy() as $key => $value) {
            $result[$key] = $value instanceof self ? $value->getArrayCopy() : $value;
        }
        return $result;
    }

    public function jsonSerialize(): mixed
    {
        return $this->getArrayCopy();
    }

    public function __debugInfo(): array
    {
        return $this->getArrayCopy();
    }
}
