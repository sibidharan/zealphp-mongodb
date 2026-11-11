<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use ZealPHP\MongoDB\Collection;
use ZealPHP\MongoDB\Exception\InvalidArgumentException;

/**
 * The $update argument is validated in PHP, before any ext call, with the same
 * rule and exception class as mongodb/mongodb (is_first_key_operator() ||
 * is_pipeline()): an update document whose first key is a "$" operator, or a
 * non-empty list of stages that each start with a "$" operator (#46).
 * No extension or server is needed: invalid input never reaches the ext.
 */
final class UpdateValidationTest extends TestCase
{
    private Collection $col;

    protected function setUp(): void
    {
        $this->col = new Collection(1, 'db', 'col');
    }

    /** @return array<string, array{array|object}> */
    public static function invalidUpdates(): array
    {
        $obj = new stdClass();
        $obj->v = 1;

        return [
            'replacement document' => [['v' => 1]],
            'replacement object' => [$obj],
            'empty document' => [[]],
            'pipeline with a non-operator stage' => [[['$set' => ['a' => 1]], ['a' => 1]]],
            'pipeline with a scalar stage' => [[['$set' => ['a' => 1]], 'x']],
            'first key not an operator' => [['v' => 1, '$set' => ['a' => 1]]],
        ];
    }

    #[DataProvider('invalidUpdates')]
    public function testUpdateOneRejectsInvalidUpdate(array|object $update): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected update operator(s) or non-empty pipeline for $update');
        $this->col->updateOne(['_id' => 1], $update);
    }

    #[DataProvider('invalidUpdates')]
    public function testUpdateManyRejectsInvalidUpdate(array|object $update): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->col->updateMany(['_id' => 1], $update);
    }

    #[DataProvider('invalidUpdates')]
    public function testFindOneAndUpdateRejectsInvalidUpdate(array|object $update): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->col->findOneAndUpdate(['_id' => 1], $update);
    }

    public function testBulkWriteUpdateOneRejectsInvalidUpdate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->col->bulkWrite([['updateOne' => [['_id' => 1], ['v' => 1]]]]);
    }
}
