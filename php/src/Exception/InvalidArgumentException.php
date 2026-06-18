<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Exception;

/**
 * Extends the official mongodb/mongodb library exception so that, in drop-in
 * use, both `catch (MongoDB\Exception\InvalidArgumentException)` and
 * `catch (MongoDB\Driver\Exception\InvalidArgumentException)` catch what the
 * ZealPHP layer throws — the official chain is
 * MongoDB\Exception\InvalidArgumentException -> MongoDB\Driver\Exception\InvalidArgumentException
 * -> \InvalidArgumentException — while existing `ZealPHP\…`/SPL catches keep working.
 */
class InvalidArgumentException extends \MongoDB\Exception\InvalidArgumentException implements ExceptionInterface
{
}
