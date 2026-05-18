<?php
namespace ZealPHP\MongoDB;

/**
 * Async bridge using eventfd + OpenSwoole Event::add().
 *
 * Flow:
 * 1. Rust spawns tokio task, returns eventfd + task_id
 * 2. PHP registers eventfd with OpenSwoole's event loop
 * 3. PHP yields on Channel::pop() (fiber suspends)
 * 4. Tokio completes → writes to eventfd → OpenSwoole fires callback
 * 5. Callback fetches result, pushes to Channel
 * 6. PHP fiber resumes with result
 *
 * No thread blocking. No C++ ABI. Pure eventfd signaling.
 */
class AsyncBridge
{
    public static function isCoroutineMode(): bool
    {
        return class_exists('\OpenSwoole\Coroutine')
            && \OpenSwoole\Coroutine::getCid() >= 0;
    }

    /**
     * Run find_one asynchronously via eventfd + Event::add.
     * Non-blocking in coroutine mode, synchronous fallback outside.
     */
    public static function findOneAsync(int $poolId, string $db, string $col, array $filter): ?array
    {
        if (!self::isCoroutineMode()) {
            // Sync fallback
            $opts = null;
            return zealphp_mongodb_find_one($poolId, $db, $col, $filter, $opts);
        }

        // Async path: eventfd + Event::add
        $async = zealphp_mongodb_find_one_async($poolId, $db, $col, $filter);
        $efd = $async['efd'];
        $taskId = $async['task_id'];

        $chan = new \OpenSwoole\Coroutine\Channel(1);

        // Register eventfd with OpenSwoole's event loop
        \OpenSwoole\Event::add($efd, function ($fd) use ($chan, $taskId, $efd) {
            // Tokio signaled — result is ready
            $result = zealphp_mongodb_get_result($taskId);
            zealphp_mongodb_close_eventfd($efd);
            \OpenSwoole\Event::del($efd);
            $chan->push($result);
        });

        // Yield the fiber — OpenSwoole schedules other coroutines
        $result = $chan->pop(10.0);

        if ($result === false) {
            zealphp_mongodb_close_eventfd($efd);
            throw new Exception\RuntimeException("Async findOne timeout");
        }

        return $result;
    }

    /**
     * Generic sync wrapper for operations without async variant yet.
     */
    public static function runSync(callable $fn): mixed
    {
        return $fn();
    }
}
