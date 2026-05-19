<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Exception;

use Throwable;

class BulkWriteException extends ServerException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        Throwable|null $previous = null,
        private readonly object|null $writeResult = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getWriteResult(): object|null
    {
        return $this->writeResult;
    }
}
