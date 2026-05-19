<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use OpenSwoole\Coroutine;

use function class_exists;
use function json_decode;
use function zealphp_mongodb_async_result;
use function zealphp_mongodb_close_efd;
use function zealphp_mongodb_count_documents;
use function zealphp_mongodb_delete_many;
use function zealphp_mongodb_delete_one;
use function zealphp_mongodb_distinct;
use function zealphp_mongodb_exec_async;
use function zealphp_mongodb_find;
use function zealphp_mongodb_find_one;
use function zealphp_mongodb_find_one_and_delete;
use function zealphp_mongodb_find_one_and_replace;
use function zealphp_mongodb_find_one_and_update;
use function zealphp_mongodb_insert_one;
use function zealphp_mongodb_replace_one;
use function zealphp_mongodb_update_many;
use function zealphp_mongodb_update_one;

class AsyncBridge
{
    public static function isCoroutineMode(): bool
    {
        return class_exists('\OpenSwoole\Coroutine')
            && class_exists('\OpenSwoole\Event')
            && Coroutine::getCid() >= 0;
    }

    public static function exec(
        int $poolId,
        string $db,
        string $col,
        string $op,
        array $filterOrDoc = [],
        array|null $updateOrPipeline = null,
    ): mixed {
        if (! self::isCoroutineMode()) {
            return self::execSync($poolId, $db, $col, $op, $filterOrDoc, $updateOrPipeline);
        }

        $async = zealphp_mongodb_exec_async($poolId, $db, $col, $op, $filterOrDoc, $updateOrPipeline);
        $efd = $async['efd'];
        $taskId = $async['task_id'];

        Coroutine\System::waitEvent($efd, OPENSWOOLE_EVENT_READ, 30);
        $json = zealphp_mongodb_async_result($taskId);
        zealphp_mongodb_close_efd($efd);

        return json_decode($json, true);
    }

    private static function execSync(
        int $poolId,
        string $db,
        string $col,
        string $op,
        array $filterOrDoc,
        array|null $updateOrPipeline,
    ): mixed {
        $opts = null;

        return match ($op) {
            'find_one' => zealphp_mongodb_find_one($poolId, $db, $col, $filterOrDoc, $opts),
            'find' => self::findSync($poolId, $db, $col, $filterOrDoc, $updateOrPipeline),
            'insert_one' => zealphp_mongodb_insert_one($poolId, $db, $col, $filterOrDoc, $opts),
            'update_one' => zealphp_mongodb_update_one($poolId, $db, $col, $filterOrDoc, $updateOrPipeline ?? [], $opts),
            'update_many' => zealphp_mongodb_update_many($poolId, $db, $col, $filterOrDoc, $updateOrPipeline ?? [], $opts),
            'delete_one' => zealphp_mongodb_delete_one($poolId, $db, $col, $filterOrDoc, $opts),
            'delete_many' => zealphp_mongodb_delete_many($poolId, $db, $col, $filterOrDoc, $opts),
            'replace_one' => zealphp_mongodb_replace_one($poolId, $db, $col, $filterOrDoc, $updateOrPipeline ?? [], $opts),
            'count_documents' => zealphp_mongodb_count_documents($poolId, $db, $col, $filterOrDoc, $opts),
            'distinct' => self::distinctSync($poolId, $db, $col, $filterOrDoc),
            'find_one_and_update' => zealphp_mongodb_find_one_and_update($poolId, $db, $col, $filterOrDoc, $updateOrPipeline ?? [], $opts),
            'find_one_and_delete' => zealphp_mongodb_find_one_and_delete($poolId, $db, $col, $filterOrDoc, $opts),
            'find_one_and_replace' => zealphp_mongodb_find_one_and_replace($poolId, $db, $col, $filterOrDoc, $updateOrPipeline ?? [], $opts),
            default => throw new Exception\RuntimeException("Unknown sync op: $op"),
        };
    }

    private static function findSync(int $poolId, string $db, string $col, array $filter, array|null $options): array
    {
        $opts = $options ?: null;
        $cursorId = zealphp_mongodb_find($poolId, $db, $col, $filter, $opts);
        $cursor = new Cursor($cursorId);
        $results = [];
        foreach ($cursor as $doc) {
            $results[] = $doc;
        }

        return $results;
    }

    private static function distinctSync(int $poolId, string $db, string $col, array $filterWithField): array
    {
        $fieldName = $filterWithField['__field'] ?? '';
        unset($filterWithField['__field']);
        $opts = null;

        return zealphp_mongodb_distinct($poolId, $db, $col, $fieldName, $filterWithField, $opts);
    }
}
