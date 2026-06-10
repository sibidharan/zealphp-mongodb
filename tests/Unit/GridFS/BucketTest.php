<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\GridFS;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\GridFS\Bucket;

use function function_exists;

/**
 * GridFS became REAL in v0.3.2 — behavioral coverage (round-trips,
 * multi-chunk, revisions, streams) lives in tests/Integration/GridFSTest.php.
 * Without the ext only the constructor's fail-fast guard is reachable.
 */
class BucketTest extends TestCase
{
    public function testConstructorFailsFastWithoutExtFunctions(): void
    {
        if (function_exists('zealphp_mongodb_gridfs_upload')) {
            $this->markTestSkipped('ext with GridFS support loaded — guard not reachable');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zealphp_mongodb.so >= 0.3.2');
        new Bucket(0, 'test_db');
    }
}
