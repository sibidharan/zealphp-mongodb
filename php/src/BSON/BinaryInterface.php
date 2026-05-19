<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

/**
 * Interface for BSON Binary type.
 */
interface BinaryInterface
{
    /**
     * Returns the Binary's data.
     */
    public function getData(): string;

    /**
     * Returns the Binary's type.
     */
    public function getType(): int;

    /**
     * Returns the Binary's data.
     */
    public function __toString(): string;
}
