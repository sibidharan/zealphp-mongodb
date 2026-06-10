<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Parity;

/**
 * Canonicalizes driver results so the C driver (ext-mongodb +
 * mongodb/mongodb) and the Rust driver (zealphp_mongodb + zealphp/mongodb)
 * can be diffed byte-for-byte. Both stacks use the SAME MongoDB\BSON\* FQCNs
 * (the polyfill mirrors the C ext namespaces), so one instanceof-by-name
 * walk covers both.
 */
final class Normalizer
{
    public static function normalize(mixed $v): mixed
    {
        if (\is_object($v)) {
            $c = \get_class($v);

            if (\str_ends_with($c, 'ObjectId')) {
                return ['$oid' => (string) $v];
            }

            if (\str_ends_with($c, 'UTCDateTime')) {
                return ['$date_ms' => (string) $v];
            }

            if (\str_ends_with($c, 'Decimal128')) {
                return ['$dec' => (string) $v];
            }

            if (\str_ends_with($c, 'Regex')) {
                /** @var object{getPattern: callable, getFlags: callable} $v */
                return ['$regex' => $v->getPattern(), '$flags' => $v->getFlags()];
            }

            if (\str_ends_with($c, 'Timestamp')) {
                return ['$ts' => (string) $v];
            }

            if (\str_ends_with($c, 'Binary')) {
                return ['$bin' => \base64_encode($v->getData()), '$st' => $v->getType()];
            }

            if (\str_ends_with($c, 'MinKey')) {
                return ['$minKey' => 1];
            }

            if (\str_ends_with($c, 'MaxKey')) {
                return ['$maxKey' => 1];
            }

            if (\str_ends_with($c, 'Int64')) {
                return (int) (string) $v;
            }

            if (\str_ends_with($c, 'Javascript')) {
                return ['$code' => $v->getCode()];
            }

            // BSONDocument / BSONArray / Document / stdClass / iterators
            if (\method_exists($v, 'getArrayCopy')) {
                return self::normalize($v->getArrayCopy());
            }

            if (\method_exists($v, 'toPHP')) {
                return self::normalize($v->toPHP());
            }

            if ($v instanceof \Traversable) {
                return self::normalize(\iterator_to_array($v));
            }

            return self::normalize((array) $v);
        }

        if (\is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = self::normalize($item);
            }

            if (! \array_is_list($out)) {
                \ksort($out);
            }

            return $out;
        }

        if (\is_float($v) && $v === \floor($v) && \abs($v) < \PHP_INT_MAX) {
            return (int) $v; // int/double wire ambiguity for whole numbers
        }

        return $v;
    }
}
