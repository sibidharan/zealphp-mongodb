<?php
namespace ZealPHP\MongoDB;

class Client
{
    private int $poolId;

    public function __construct(?string $uri = 'mongodb://localhost:27017', array $uriOptions = [], array $driverOptions = [])
    {
        $this->poolId = zealphp_mongodb_connect($uri);
    }

    public function __get(string $name): Database
    {
        return $this->selectDatabase($name);
    }

    public function selectDatabase(string $databaseName, array $options = []): Database
    {
        return new Database($this->poolId, $databaseName, $options);
    }

    public function getDatabase(string $databaseName, array $options = []): Database
    {
        return $this->selectDatabase($databaseName, $options);
    }

    public function selectCollection(string $databaseName, string $collectionName, array $options = []): Collection
    {
        return new Collection($this->poolId, $databaseName, $collectionName, $options);
    }

    public function getCollection(string $databaseName, string $collectionName, array $options = []): Collection
    {
        return $this->selectCollection($databaseName, $collectionName, $options);
    }

    public function listDatabases(array $options = []): array
    {
        $names = zealphp_mongodb_list_databases($this->poolId);
        $result = [];
        foreach ($names as $name) {
            $result[] = ['name' => $name];
        }
        return $result;
    }

    public function listDatabaseNames(array $options = []): array
    {
        return zealphp_mongodb_list_databases($this->poolId);
    }

    public function getPoolId(): int
    {
        return $this->poolId;
    }

    public function dropDatabase(string $databaseName, array $options = []): array
    {
        zealphp_mongodb_drop_database($this->poolId, $databaseName);
        return ['ok' => 1];
    }

    public function startSession(array $options = []): Session
    {
        return new Session($this->poolId, $options);
    }

    public function watch(array $pipeline = [], array $options = []): ChangeStream
    {
        return new ChangeStream();
    }

    public function __toString(): string { return 'mongodb://...'; }
    public function __debugInfo(): array { return ['poolId' => $this->poolId]; }

    private ?ReadConcern $readConcern = null;
    private ?WriteConcern $writeConcern = null;
    private ?ReadPreference $readPreference = null;

    public function getReadConcern(): ReadConcern { return $this->readConcern ?? new ReadConcern(); }
    public function getWriteConcern(): WriteConcern { return $this->writeConcern ?? new WriteConcern(1); }
    public function getReadPreference(): ReadPreference { return $this->readPreference ?? new ReadPreference(ReadPreference::PRIMARY); }
    public function getTypeMap(): array { return ['root' => 'array', 'document' => 'array', 'array' => 'array']; }

    public function __destruct()
    {
        @zealphp_mongodb_close($this->poolId);
    }
}
