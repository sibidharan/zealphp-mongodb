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

    public function getPoolId(): int
    {
        return $this->poolId;
    }

    public function __destruct()
    {
        @zealphp_mongodb_close($this->poolId);
    }
}
