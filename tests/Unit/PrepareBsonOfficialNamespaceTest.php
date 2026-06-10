<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Javascript;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\Timestamp;
use PHPUnit\Framework\TestCase;
use ZealPHP\MongoDB\Collection;

use function base64_encode;

/**
 * Drop-in contract pin (found by the parity rig): user code written for the
 * official library passes MongoDB\BSON\* value objects — prepareBSON() must
 * serialize them to extended JSON, not crash in the generic object branch on
 * their private props ("\0" keys).
 */
final class PrepareBsonOfficialNamespaceTest extends TestCase
{
    public function testOfficialNamespaceValueObjectsSerialize(): void
    {
        $doc = Collection::prepareBSON([
            'bin' => new Binary("\x01\xff", Binary::TYPE_GENERIC),
            'dec' => new Decimal128('12.34'),
            'ts' => new Timestamp(1, 2),
            'js' => new Javascript('function () {}'),
            'min' => new MinKey(),
            'max' => new MaxKey(),
        ]);

        $this->assertSame(base64_encode("\x01\xff"), $doc['bin']['$binary']['base64'] ?? null);
        $this->assertSame('12.34', $doc['dec']['$numberDecimal'] ?? null);
        $this->assertArrayHasKey('$timestamp', $doc['ts']);
        $this->assertArrayHasKey('$code', $doc['js']);
        $this->assertSame(1, $doc['min']['$minKey'] ?? null);
        $this->assertSame(1, $doc['max']['$maxKey'] ?? null);
    }

    public function testPlainObjectWithPrivatePropsDoesNotCrash(): void
    {
        $obj = new class {
            public string $visible = 'yes';
            private string $hidden = 'no'; // @phpstan-ignore-line
        };

        $out = Collection::prepareBSON(['o' => $obj]);
        $this->assertSame('yes', $out['o']->visible);
        $this->assertObjectNotHasProperty('hidden', $out['o']);
    }
}
