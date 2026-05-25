<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Operation\FindOneAndReplace;
use ZealPHP\MongoDB\Operation\FindOneAndUpdate;

class OperationConstantsTest extends TestCase
{
    public function testFindOneAndUpdateReturnDocumentBefore(): void
    {
        $this->assertSame(1, FindOneAndUpdate::RETURN_DOCUMENT_BEFORE);
    }

    public function testFindOneAndUpdateReturnDocumentAfter(): void
    {
        $this->assertSame(2, FindOneAndUpdate::RETURN_DOCUMENT_AFTER);
    }

    public function testFindOneAndReplaceReturnDocumentBefore(): void
    {
        $this->assertSame(1, FindOneAndReplace::RETURN_DOCUMENT_BEFORE);
    }

    public function testFindOneAndReplaceReturnDocumentAfter(): void
    {
        $this->assertSame(2, FindOneAndReplace::RETURN_DOCUMENT_AFTER);
    }
}
