<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use MongoDB\Model\BSONDocument;

/**
 * Backward-compatible alias — new code should use BSONDocument directly.
 */
class Document extends BSONDocument
{
}
