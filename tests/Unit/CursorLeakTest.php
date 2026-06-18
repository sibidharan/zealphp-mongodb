<?php

declare(strict_types=1);

/*
 * Regression test for the production memory leak: Cursor::toArray() orphaned its
 * native cursor handle. It set $this->cursorId = null WITHOUT calling
 * zealphp_mongodb_cursor_close(), and __destruct() then short-circuits on the
 * null cursorId — so the Rust-side cursor (and its buffered result set) was never
 * freed. Under a long-running OpenSwoole worker this grew without bound (~97 GB
 * RSS / 10 h in prod). The cursor_next exhaustion path never removes the entry
 * from the global CURSORS map either, so the PHP wrapper MUST guarantee a close.
 *
 * The native zealphp_mongodb_* functions are unavailable without the loaded ext;
 * the global shims below record which cursor ids were closed/drained. Cursor.php
 * imports these via `use function` (global binding), so the unqualified calls
 * resolve to these definitions.
 */

namespace {

    use ZealPHP\MongoDB\Tests\Unit\CursorLeakTest;

    use function function_exists;

    if (! function_exists('zealphp_mongodb_cursor_close')) {
        function zealphp_mongodb_cursor_close(int $cursorId): void
        {
            CursorLeakTest::$closed[] = $cursorId;
        }
    }

    if (! function_exists('zealphp_mongodb_cursor_to_array')) {
        function zealphp_mongodb_cursor_to_array(int $cursorId): array
        {
            CursorLeakTest::$drained[] = $cursorId;

            return [['_id' => 1], ['_id' => 2]];
        }
    }

    if (! function_exists('zealphp_mongodb_cursor_next')) {
        function zealphp_mongodb_cursor_next(int $cursorId): array|null
        {
            $n = CursorLeakTest::$nextCalls[$cursorId] ?? 0;
            CursorLeakTest::$nextCalls[$cursorId] = $n + 1;

            return $n === 0 ? ['_id' => 99] : null;
        }
    }
}

namespace ZealPHP\MongoDB\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use ZealPHP\MongoDB\Cursor;

    use function extension_loaded;

    class CursorLeakTest extends TestCase
    {
        /** @var list<int> cursor ids passed to the cursor_close shim */
        public static array $closed = [];
        /** @var list<int> cursor ids passed to the cursor_to_array shim */
        public static array $drained = [];
        /** @var array<int, int> per-cursor-id call counter for the cursor_next shim */
        public static array $nextCalls = [];

        protected function setUp(): void
        {
            if (extension_loaded('zealphp_mongodb')) {
                $this->markTestSkipped('Native extension loaded; shims are not in effect.');
            }

            self::$closed    = [];
            self::$drained   = [];
            self::$nextCalls = [];
        }

        public function testToArrayClosesNativeCursorOnDrainPath(): void
        {
            $cursor = new Cursor(4242);

            $cursor->toArray();

            $this->assertContains(
                4242,
                self::$closed,
                'Cursor::toArray() must close its native cursor; otherwise the Rust-side '
                . 'cursor and its buffered result set leak on every find()->toArray().',
            );
        }
    }
}
