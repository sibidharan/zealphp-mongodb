<?php

declare(strict_types=1);

namespace ZealPHP\MongoDB;

use MongoDB\Driver\Manager;
use Stringable;

use function array_map;
use function array_merge;
use function function_exists;
use function http_build_query;
use function is_array;
use function is_bool;
use function is_scalar;
use function str_contains;
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

    private readonly string $uri;

    public function __construct(string|null $uri = 'mongodb://localhost:27017', private readonly array $uriOptions = [], private readonly array $driverOptions = [])
    {
        // Retain the connection parameters instead of discarding them (#36) so
        // getManager() can hand back an equivalent Manager and scalar uriOptions
        // can be folded into the connection URI.
        $this->uri = $uri ?? 'mongodb://localhost:27017';
        $this->poolId = zealphp_mongodb_connect(self::mergeUriOptions($this->uri, $uriOptions));
    }

    /**
     * Fold scalar uriOptions into the connection URI query string so they reach
     * the driver (#36). Non-scalar options (e.g. tagSets) are left to a future
     * pass.
     *
     * @param array<string, mixed> $uriOptions
     */
    private static function mergeUriOptions(string $uri, array $uriOptions): string
    {
        $scalars = [];
        foreach ($uriOptions as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $scalars[$key] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        if ($scalars === []) {
            return $uri;
        }

        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($scalars);
    }

    /**
     * The underlying MongoDB\Driver\Manager, like the official Client (#35).
     */
    public function getManager(): Manager
    {
        return new Manager($this->uri, $this->uriOptions, $this->driverOptions);
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
        return new Database($this->poolId, $databaseName, $options, $this);
    }

    public function getDatabase(string $databaseName, array $options = []): Database
    {
        return $this->selectDatabase($databaseName, $options);
    }

    public function selectCollection(string $databaseName, string $collectionName, array $options = []): Collection
    {
        return new Collection($this->poolId, $databaseName, $collectionName, $options, $this);
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

    // The concern/preference getters now reflect the uriOptions the client was
    // built with instead of always returning hard-coded defaults (#32).
    public function getReadConcern(): ReadConcern
    {
        return $this->readConcern ?? new ReadConcern($this->uriOptions['readConcernLevel'] ?? null);
    }

    public function getWriteConcern(): WriteConcern
    {
        return $this->writeConcern ?? new WriteConcern($this->uriOptions['w'] ?? 1);
    }

    public function getReadPreference(): ReadPreference
    {
        return $this->readPreference ?? new ReadPreference($this->uriOptions['readPreference'] ?? ReadPreference::PRIMARY);
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
