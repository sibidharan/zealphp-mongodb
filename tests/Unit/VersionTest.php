<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Client;

use function preg_match;

/**
 * #71: the library exposes a version constant so "which version am I running?"
 * is answerable at runtime (and a lib/ext skew is detectable).
 */
class VersionTest extends TestCase
{
    public function testVersionConstantIsSemver(): void
    {
        $this->assertSame(1, preg_match('/^\d+\.\d+\.\d+$/', Client::VERSION));
    }
}
