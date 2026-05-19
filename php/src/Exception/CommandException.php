<?php

namespace ZealPHP\MongoDB\Exception;

class CommandException extends ServerException
{
    private ?object $resultDocument;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?object $resultDocument = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->resultDocument = $resultDocument;
    }

    public function getResultDocument(): ?object
    {
        return $this->resultDocument;
    }
}
