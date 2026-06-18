<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Exception;

use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\Exception as DriverException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\WriteError;
use MongoDB\Driver\WriteResult;
use Throwable;

use function array_pad;
use function count;
use function explode;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Translates the Rust ext's structured error payload (see ext/src/errconv.rs)
 * into the typed `MongoDB\Driver\Exception\*` the official driver throws —
 * carrying the real server code, codeName and per-write errors. This is the
 * PHP half of cluster C1 (#38, #22, #41, #42, ...): the ext encodes, the
 * wrapper around each server-touching op decodes and rethrows.
 *
 * Errors without the sentinel (client-side validation, pool, bson) and errors
 * that are already typed pass through unchanged.
 */
final class ErrorMapper
{
    private const SENTINEL = "\u{1}ZMERR\u{1}";
    private const WE_DELIM  = "\u{1}WE\u{1}";
    private const FIELD     = "\u{1}";

    public static function map(Throwable $e): Throwable
    {
        if ($e instanceof DriverException) {
            return $e;
        }

        $message = $e->getMessage();
        if (! str_starts_with($message, self::SENTINEL)) {
            return $e;
        }

        $payload  = substr($message, strlen(self::SENTINEL));
        $records  = explode(self::WE_DELIM, $payload);
        $head     = explode(self::FIELD, $records[0]);
        [$kind, $code, $codeName, $labels, $errmsg] = array_pad($head, 5, '');
        $code     = (int) $code;

        $writeErrors = [];
        for ($i = 1, $n = count($records); $i < $n; $i++) {
            $fields = explode(self::FIELD, $records[$i]);
            // index, code, codeName, errmsg
            $writeErrors[] = new WriteError(
                (int) ($fields[0] ?? 0),
                (string) ($fields[3] ?? ''),
                (int) ($fields[1] ?? 0),
            );
        }

        return match ($kind) {
            'write' => new BulkWriteException(
                $errmsg,
                $code,
                $e,
                new WriteResult(0, 0, 0, 0, 0, null, $writeErrors),
            ),
            'writeconcern' => new BulkWriteException(
                $errmsg,
                $code,
                $e,
                new WriteResult(0, 0, 0, 0, 0, null, $writeErrors),
            ),
            'command' => new CommandException($errmsg, $code, $e),
            default   => new DriverRuntimeException($errmsg !== '' ? $errmsg : $message, $code, $e),
        };
    }
}
