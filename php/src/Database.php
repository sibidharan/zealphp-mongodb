<?php
namespace ZealPHP\MongoDB;

class Database
{
    public function __construct(
        private int $poolId,
        private string $databaseName,
        private array $options = []
    ) {}

    public function __get(string $name): Collection
    {
        return $this->selectCollection($name);
    }

    public function selectCollection(string $collectionName, array $options = []): Collection
    {
        return new Collection($this->poolId, $this->databaseName, $collectionName, $options);
    }

    public function getCollection(string $collectionName, array $options = []): Collection
    {
        return $this->selectCollection($collectionName, $options);
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getPoolId(): int
    {
        return $this->poolId;
    }

    private ?ReadConcern $readConcern = null;
    private ?WriteConcern $writeConcern = null;
    private ?ReadPreference $readPreference = null;

    public function getReadConcern(): ReadConcern { return $this->readConcern ?? new ReadConcern(); }
    public function getWriteConcern(): WriteConcern { return $this->writeConcern ?? new WriteConcern(1); }
    public function getReadPreference(): ReadPreference { return $this->readPreference ?? new ReadPreference(ReadPreference::PRIMARY); }
    public function getTypeMap(): array { return ['root' => 'array', 'document' => 'array', 'array' => 'array']; }
}
