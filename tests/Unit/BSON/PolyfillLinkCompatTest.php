<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit\BSON;

use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const PHP_BINARY;

/**
 * Pins the polyfill ↔ mongodb/mongodb link-compatibility contract (issue: the
 * wgvpn "password_verify hang", ext-zealphp#36 — actually a worker-killing
 * E_COMPILE_ERROR on the FIRST real document wrapDoc()).
 *
 * mongodb/mongodb 1.x declares BSONDocument::bsonSerialize() UNTYPED
 * (#[ReturnTypeWillChange]); 2.x declares `array|stdClass`. The polyfill's
 * MongoDB\BSON\Serializable interface must therefore stay UNTYPED — a typed
 * interface method makes every 1.x model class fatally incompatible at
 * class-link time, killing the worker on the first non-null query result.
 *
 * Each compile-shape probe runs in a PHP SUBPROCESS because an incompatible
 * declaration is an E_COMPILE_ERROR — it would kill the PHPUnit process, not
 * fail an assertion.
 */
final class PolyfillLinkCompatTest extends TestCase
{
    private const POLYFILL = __DIR__ . '/../../../php/src/compat/bson_polyfill.php';

    /**
     * Runs a PHP snippet in a `php -n` subprocess (no php.ini, so a host
     * ext-mongodb can never preempt the polyfill under test). When
     * $loadPolyfill is true the polyfill is required first; the vendored-lib
     * probe instead loads ONLY vendor/autoload.php so composer's real
     * files-autoload order (lib functions.php before the polyfill) applies.
     */
    private function compileProbe(string $code, bool $loadPolyfill = true): array
    {
        $script = sprintf(
            '<?php %s%s echo "LINKED";',
            $loadPolyfill ? sprintf('require %s; ', var_export(self::POLYFILL, true)) : '',
            $code,
        );
        $tmp = tempnam(sys_get_temp_dir(), 'polyfill_probe_');
        file_put_contents($tmp, $script);
        try {
            exec(sprintf('%s -n -d display_errors=1 %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($tmp)), $out, $exit);
        } finally {
            unlink($tmp);
        }

        return ['exit' => $exit, 'out' => implode("\n", $out)];
    }

    public function testUntypedOneXShapeImplementorLinks(): void
    {
        // mongodb/mongodb 1.x shape: untyped, #[ReturnTypeWillChange]
        $r = $this->compileProbe(<<<'PHP'
            class OneXShape implements MongoDB\BSON\Serializable, MongoDB\BSON\Unserializable {
                #[ReturnTypeWillChange]
                public function bsonSerialize() { return ['a' => 1]; }
                #[ReturnTypeWillChange]
                public function bsonUnserialize(array $data) {}
            }
            new OneXShape();
        PHP);
        $this->assertSame(0, $r['exit'], "1.x-shape implementor must link against the polyfill interface; got: {$r['out']}");
        $this->assertStringContainsString('LINKED', $r['out']);
        $this->assertStringNotContainsString('must be compatible', $r['out']);
    }

    public function testTypedTwoXShapeImplementorLinks(): void
    {
        // mongodb/mongodb 2.x shape: typed (covariant tightening — always legal)
        $r = $this->compileProbe(<<<'PHP'
            class TwoXShape implements MongoDB\BSON\Serializable, MongoDB\BSON\Unserializable {
                public function bsonSerialize(): array|stdClass { return ['a' => 1]; }
                public function bsonUnserialize(array $data): void {}
            }
            new TwoXShape();
        PHP);
        $this->assertSame(0, $r['exit'], "2.x-shape implementor must link against the polyfill interface; got: {$r['out']}");
        $this->assertStringContainsString('LINKED', $r['out']);
    }

    public function testVendoredLibraryModelClassesLink(): void
    {
        // The real thing: whatever mongodb/mongodb major is installed, its
        // model classes must instantiate (this is the exact line that killed
        // the wgvpn worker on the first real findOne() result).
        $autoload = __DIR__ . '/../../../vendor/autoload.php';
        $r = $this->compileProbe(sprintf(
            'require %s; $d = new MongoDB\Model\BSONDocument(["a" => 1]); $l = new MongoDB\Model\BSONArray([1]); ',
            var_export($autoload, true),
        ), loadPolyfill: false);
        $this->assertSame(0, $r['exit'], "Installed mongodb/mongodb model classes must link; got: {$r['out']}");
        $this->assertStringContainsString('LINKED', $r['out']);
    }
}
