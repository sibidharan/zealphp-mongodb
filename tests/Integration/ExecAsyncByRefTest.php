<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

use function extension_loaded;
use function function_exists;

/**
 * Regression for the by-ref argument bug (#3).
 *
 * ext-php-rs-derive (0.10.x) marks every `Option<&T>` parameter as PHP
 * pass-by-reference, so a literal array/pipeline at arg #6 of
 * zealphp_mongodb_exec_async was rejected at the call site with
 * "Argument #6 ($update_or_pipeline) could not be passed by reference".
 * The fix changes `update_or_pipeline` from `Option<&Zval>` to a bare `&Zval`,
 * which the same macro does NOT mark by-ref. A plain `&Zval` (arg #5,
 * filter_or_doc) was never affected — guarded here too.
 *
 * This checks the generated arg-info via reflection, so it needs only the loaded
 * extension (no MongoDB server / pool).
 */
final class ExecAsyncByRefTest extends TestCase
{
    public function testExecAsyncArgsAreNotPassedByReference(): void
    {
        if (! extension_loaded('zealphp-mongodb-ext') || ! function_exists('zealphp_mongodb_exec_async')) {
            self::markTestSkipped('zealphp-mongodb-ext not loaded');
        }

        $params = (new ReflectionFunction('zealphp_mongodb_exec_async'))->getParameters();

        // arg #6 update_or_pipeline — the fix: literal arrays/pipelines must pass.
        self::assertFalse(
            $params[5]->isPassedByReference(),
            'update_or_pipeline (arg #6) must NOT be by-reference so literal pipelines pass',
        );
        // arg #5 filter_or_doc — bare &Zval, never by-ref; guard against regression.
        self::assertFalse(
            $params[4]->isPassedByReference(),
            'filter_or_doc (arg #5) must NOT be by-reference',
        );
    }
}
