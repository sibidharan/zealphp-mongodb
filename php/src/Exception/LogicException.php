<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Exception;

/**
 * Extends the official driver LogicException so that, in drop-in use,
 * `catch (MongoDB\Driver\Exception\LogicException)` catches what the ZealPHP
 * layer throws (e.g. the single-iteration cursor contract), while existing
 * `ZealPHP\…`/SPL `\LogicException` catches keep working.
 */
class LogicException extends \MongoDB\Driver\Exception\LogicException implements ExceptionInterface
{
}
