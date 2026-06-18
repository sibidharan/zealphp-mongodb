<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use Stringable;

use function array_map;
use function array_merge;
use function function_exists;
use function is_array;
use function zealphp_mongodb_close;
use function zealphp_mongodb_connect;
use function zealphp_mongodb_drop_database;
use function zealphp_mongodb_version;

class Client implements Stringable
{
    /**
     * The zealphp/mongodb PHP-library version. Lets an app answer "which
     * version am I running?" at runtime and detect a lib/ext mismatch — the
     * native ext build advertised its own (possibly skewed) version with no
     * library-side counterpart before (#71).
     */
    public const VERSION = '0.4.0';

    private readonly int $poolId;

    public function __construct(string|null $uri = 'mongodb://localhost:27017', array $uriOptions = [], array $driverOptions = [])
    {
        $this->poolId = zealphp_mongodb_connect($uri);
    }

    public function __get(string $name): Database
    {
        return $this->selectDatabase($name);
    }

    /**
     * Both the PHP library version and the loaded native ext version, so a
     * lib/ext skew is observable at runtime (#71).
     *
     * @return array{library: string, extension: string|null}
     */
    public function getVersions(): array
    {
        return [
            'library' => self::VERSION,
            'extension' => function_exists('zealphp_mongodb_version') ? zealphp_mongodb_version() : null,
        ];
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

    /** @return list<DatabaseInfo> */
    public function listDatabases(array $options = []): array
    {
        // Run the admin listDatabases command so each entry carries name +
        // sizeOnDisk + empty (and respects filter/nameOnly), wrapped in a
        // DatabaseInfo like the official driver — not a bare ['name'=>…] (#33).
        $cmd = ['listDatabases' => 1];
        foreach (['filter', 'nameOnly', 'authorizedDatabases', 'comment'] as $key) {
            if (! isset($options[$key])) {
                continue;
            }

            $cmd[$key] = $options[$key];
        }

        $result = $this->selectDatabase('admin')->command($cmd);
        $databases = $result['databases'] ?? [];

        return array_map(
            static fn ($d) => new DatabaseInfo(is_array($d) ? $d : (array) $d),
            $databases,
        );
    }

    /** @return list<string> */
    public function listDatabaseNames(array $options = []): array
    {
        return array_map(
            static fn (DatabaseInfo $info) => $info->getName(),
            $this->listDatabases(array_merge($options, ['nameOnly' => true])),
        );
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

    /** Watch the whole deployment for changes (replica set required). */
    public function watch(array $pipeline = [], array $options = []): ChangeStream
    {
        return ChangeStream::open($this->poolId, '', '', $pipeline, $options);
    }

    public function __toString(): string
    {
        return 'mongodb://...';
    }

    public function __debugInfo(): array
    {
        return ['poolId' => $this->poolId];
    }

    private ReadConcern|null $readConcern = null;
    private WriteConcern|null $writeConcern = null;
    private ReadPreference|null $readPreference = null;

    public function getReadConcern(): ReadConcern
    {
        return $this->readConcern ?? new ReadConcern();
    }

    public function getWriteConcern(): WriteConcern
    {
        return $this->writeConcern ?? new WriteConcern(1);
    }

    public function getReadPreference(): ReadPreference
    {
        return $this->readPreference ?? new ReadPreference(ReadPreference::PRIMARY);
    }

    public function getTypeMap(): array
    {
        return ['root' => 'array', 'document' => 'array', 'array' => 'array'];
    }

    public function __destruct()
    {
        @zealphp_mongodb_close($this->poolId);
    }
}
