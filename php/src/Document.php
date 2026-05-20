<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use ArrayObject;
use JsonSerializable;

class Document extends ArrayObject implements JsonSerializable
{
    public function __construct(array|object $input = [])
    {
        parent::__construct((array) $input, ArrayObject::ARRAY_AS_PROPS);
    }

    public function jsonSerialize(): array
    {
        return $this->getArrayCopy();
    }

    public function __debugInfo(): array
    {
        return $this->getArrayCopy();
    }
}
