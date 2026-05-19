<?php

namespace ZealPHP\MongoDB\Exception;

class RuntimeException extends \RuntimeException implements ExceptionInterface
{
    /**
     * Check whether an error label is associated with an exception.
     *
     * Returns false by default. Subclasses may override to provide
     * label-based error categorization.
     */
    public function hasErrorLabel(string $label): bool
    {
        return false;
    }
}
