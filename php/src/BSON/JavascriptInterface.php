<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\BSON;

/**
 * Interface for BSON Javascript type.
 */
interface JavascriptInterface
{
    /**
     * Returns the Javascript's code.
     */
    public function getCode(): string;

    /**
     * Returns the Javascript's scope document, if any.
     */
    public function getScope(): object|null;

    /**
     * Returns the Javascript's code.
     */
    public function __toString(): string;
}
