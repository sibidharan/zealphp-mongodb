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

/**
 * Drop-in contract pin (found by the parity rig): user code written for the
 * official library passes MongoDB\BSON\* value objects — since #45 prepareBSON()
 * passes them through UNCHANGED (the ext encodes each by class), instead of
 * flattening them to extended-JSON arrays. They must not crash in the generic
 * object branch on their private props ("\0" keys).
 */
final class PrepareBsonOfficialNamespaceTest extends TestCase
{
    public function testOfficialNamespaceValueObjectsPassThrough(): void
    {
        $bin = new Binary("\x01\xff", Binary::TYPE_GENERIC);
        $dec = new Decimal128('12.34');
        $ts = new Timestamp(1, 2);
        $js = new Javascript('function () {}');
        $min = new MinKey();
        $max = new MaxKey();

        $doc = Collection::prepareBSON([
            'bin' => $bin,
            'dec' => $dec,
            'ts' => $ts,
            'js' => $js,
            'min' => $min,
            'max' => $max,
        ]);

        $this->assertSame($bin, $doc['bin']);
        $this->assertSame($dec, $doc['dec']);
        $this->assertSame($ts, $doc['ts']);
        $this->assertSame($js, $doc['js']);
        $this->assertSame($min, $doc['min']);
        $this->assertSame($max, $doc['max']);
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
