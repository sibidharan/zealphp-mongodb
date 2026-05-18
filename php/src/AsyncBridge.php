<?php
namespace ZealPHP\MongoDB;

/**
 * Bridges synchronous Rust extension calls into OpenSwoole coroutines.
 *
 * Strategy: spawn the blocking Rust call in a child coroutine and wait
 * on a Channel. The child coroutine blocks its own stack but the parent
 * yields on Channel::pop(), allowing the worker to handle other requests.
 */
class AsyncBridge
{
    public static function isCoroutineMode(): bool
    {
        return class_exists('\OpenSwoole\Coroutine')
            && \OpenSwoole\Coroutine::getCid() >= 0;
    }

    /**
     * Run a blocking callable asynchronously via child coroutine + Channel.
     */
    public static function run(callable $fn): mixed
    {
        if (!self::isCoroutineMode()) {
            return $fn();
        }

        $chan = new \OpenSwoole\Coroutine\Channel(1);

        // Child coroutine — blocks on the Rust block_on() call
        // but that's OK because it's a separate coroutine stack
        \OpenSwoole\Coroutine::create(function () use ($fn, $chan) {
            try {
                $result = $fn();
                $chan->push(['v' => $result]);
            } catch (\Throwable $e) {
                $chan->push(['e' => $e->getMessage()]);
            }
        });

        // Parent yields here — Channel::pop() is coroutine-aware
        $resp = $chan->pop(10.0); // 10s timeout

        if ($resp === false) {
            throw new Exception\RuntimeException("MongoDB query timeout");
        }
        if (isset($resp['e'])) {
            throw new Exception\RuntimeException($resp['e']);
        }
        return $resp['v'];
    }
}
