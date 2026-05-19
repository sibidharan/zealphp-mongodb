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

    public function command(array|object $command, array $options = []): array
    {
        $cmd = Collection::prepareBSON((array)$command);
        $result = zealphp_mongodb_run_command($this->poolId, $this->databaseName, $cmd);
        return is_array($result) ? $result : [$result];
    }

    public function aggregate(array $pipeline, array $options = []): ArrayCursor
    {
        $pipeline = Collection::prepareBSON($pipeline);
        $cmd = ['aggregate' => 1, 'pipeline' => $pipeline, 'cursor' => new \stdClass()];
        $result = $this->command($cmd);
        $docs = $result['cursor']['firstBatch'] ?? [];
        return new ArrayCursor($docs);
    }

    public function createCollection(string $collectionName, array $options = []): array
    {
        zealphp_mongodb_create_collection($this->poolId, $this->databaseName, $collectionName);
        return ['ok' => 1];
    }

    public function dropCollection(string $collectionName, array $options = []): array
    {
        zealphp_mongodb_drop_collection($this->poolId, $this->databaseName, $collectionName);
        return ['ok' => 1];
    }

    public function drop(array $options = []): array
    {
        zealphp_mongodb_drop_database($this->poolId, $this->databaseName);
        return ['ok' => 1];
    }

    public function listCollections(array $options = []): array
    {
        $cmd = ['listCollections' => 1];
        $result = $this->command($cmd);
        return $result['cursor']['firstBatch'] ?? [];
    }

    public function listCollectionNames(array $options = []): array
    {
        return zealphp_mongodb_list_collection_names($this->poolId, $this->databaseName);
    }

    public function modifyCollection(string $collectionName, array $collectionOptions, array $options = []): array
    {
        $cmd = array_merge(['collMod' => $collectionName], $collectionOptions);
        return $this->command($cmd);
    }

    public function renameCollection(string $from, string $to, ?string $toDb = null, array $options = []): array
    {
        $col = $this->selectCollection($from);
        return $col->rename($to, $toDb);
    }

    public function selectGridFSBucket(array $options = []): GridFS\Bucket
    {
        return new GridFS\Bucket($this->poolId, $this->databaseName, $options);
    }

    public function withOptions(array $options = []): self
    {
        $new = clone $this;
        $new->options = array_merge($this->options, $options);
        return $new;
    }

    public function __toString(): string { return $this->databaseName; }
    public function __debugInfo(): array { return ['databaseName' => $this->databaseName, 'poolId' => $this->poolId]; }

    private ?ReadConcern $readConcern = null;
    private ?WriteConcern $writeConcern = null;
    private ?ReadPreference $readPreference = null;

    public function getReadConcern(): ReadConcern { return $this->readConcern ?? new ReadConcern(); }
    public function getWriteConcern(): WriteConcern { return $this->writeConcern ?? new WriteConcern(1); }
    public function getReadPreference(): ReadPreference { return $this->readPreference ?? new ReadPreference(ReadPreference::PRIMARY); }
    public function getTypeMap(): array { return ['root' => 'array', 'document' => 'array', 'array' => 'array']; }
}
