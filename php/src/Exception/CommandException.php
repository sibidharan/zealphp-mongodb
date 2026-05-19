<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Exception;

use Throwable;

class CommandException extends ServerException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        Throwable|null $previous = null,
        private readonly object|null $resultDocument = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getResultDocument(): object|null
    {
        return $this->resultDocument;
    }
}
