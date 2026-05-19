<?php

namespace ZealPHP\MongoDB\Exception;

class BulkWriteException extends ServerException
{
    private ?object $writeResult;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?object $writeResult = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->writeResult = $writeResult;
    }

    public function getWriteResult(): ?object
    {
        return $this->writeResult;
    }
}
