<?php

namespace TCG\Voyager\Database\Schema;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\PDO\Connection as PdoConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table as DoctrineTable;
use Illuminate\Support\Facades\DB;
use TCG\Voyager\Database\Types\Type;

abstract class SchemaManager
{
    // todo: trim parameters

    /** @var DoctrineConnection|null */
    protected static $doctrineConnection = null;

    public static function __callStatic($method, $args)
    {
        return static::manager()->$method(...$args);
    }

    public static function manager(): AbstractSchemaManager
    {
        return static::getDatabaseConnection()->createSchemaManager();
    }

    /**
     * Get (or create and cache) a Doctrine DBAL connection that shares the same
     * underlying PDO as Laravel's current DB connection.  This is critical for
     * SQLite :memory: databases where every new PDO object is a separate,
     * empty database.
     */
    public static function getDatabaseConnection(): DoctrineConnection
    {
        // Re-use the cached connection as long as the underlying PDO hasn't changed.
        $laravelPdo = DB::connection()->getPdo();

        if (static::$doctrineConnection !== null) {
            try {
                $nativePdo = static::$doctrineConnection->getNativeConnection();
                // Unwrap Doctrine\DBAL\Driver\PDO\Connection to get the raw PDO
                if ($nativePdo instanceof \PDO && $nativePdo === $laravelPdo) {
                    return static::$doctrineConnection;
                }
            } catch (\Throwable $e) {
                // Ignore – rebuild the connection below.
            }
            static::$doctrineConnection = null;
        }

        $driverName = DB::connection()->getDriverName();

        // Build an anonymous driver that proxies every call to the real DBAL
        // driver but overrides connect() to reuse the existing Laravel PDO,
        // ensuring both share the same physical connection (required for SQLite
        // :memory: databases and for proper transaction sharing on all drivers).
        $realDriver = match ($driverName) {
            'mysql', 'mariadb' => new \Doctrine\DBAL\Driver\PDO\MySQL\Driver(),
            'pgsql'            => new \Doctrine\DBAL\Driver\PDO\PgSQL\Driver(),
            default            => new \Doctrine\DBAL\Driver\PDO\SQLite\Driver(),
        };

        $pdo = $laravelPdo;

        $wrappedDriver = new class($realDriver, $pdo) extends AbstractSQLiteDriver {
            private \Doctrine\DBAL\Driver $realDriver;
            private \PDO $pdo;

            public function __construct(\Doctrine\DBAL\Driver $realDriver, \PDO $pdo)
            {
                $this->realDriver = $realDriver;
                $this->pdo        = $pdo;
            }

            public function connect(array $params): PdoConnection
            {
                return new PdoConnection($this->pdo);
            }

            public function getDatabasePlatform()
            {
                return $this->realDriver->getDatabasePlatform();
            }

            public function getSchemaManager(DoctrineConnection $conn, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): AbstractSchemaManager
            {
                return $this->realDriver->getSchemaManager($conn, $platform);
            }

            public function getExceptionConverter(): \Doctrine\DBAL\Driver\API\ExceptionConverter
            {
                return $this->realDriver->getExceptionConverter();
            }
        };

        static::$doctrineConnection = new DoctrineConnection(
            ['driver' => 'pdo_sqlite'],
            $wrappedDriver
        );

        return static::$doctrineConnection;
    }

    /**
     * Reset the cached Doctrine connection (e.g. after a new test setUp).
     */
    public static function resetConnection(): void
    {
        static::$doctrineConnection = null;
    }

    public static function tableExists($table)
    {
        if (!is_array($table)) {
            $table = [$table];
        }

        return static::manager()->tablesExist($table);
    }

    public static function listTables()
    {
        $tables = [];

        foreach (static::manager()->listTableNames() as $tableName) {
            $tables[$tableName] = static::listTableDetails($tableName);
        }

        return $tables;
    }

    /**
     * @param string $tableName
     *
     * @return \TCG\Voyager\Database\Schema\Table
     */
    public static function listTableDetails($tableName)
    {
        $columns = static::manager()->listTableColumns($tableName);

        $foreignKeys = [];
        if (static::manager()->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            $foreignKeys = static::manager()->listTableForeignKeys($tableName);
        }

        $indexes = static::manager()->listTableIndexes($tableName);

        return new Table($tableName, $columns, $indexes, [], $foreignKeys, []);
    }

    /**
     * Describes given table.
     *
     * @param string $tableName
     *
     * @return \Illuminate\Support\Collection
     */
    public static function describeTable($tableName)
    {
        Type::registerCustomPlatformTypes();

        $table = static::listTableDetails($tableName);

        return collect($table->columns)->map(function ($column) use ($table) {
            $columnArr = Column::toArray($column);

            $columnArr['field'] = $columnArr['name'];
            $columnArr['type'] = $columnArr['type']['name'];

            // Set the indexes and key
            $columnArr['indexes'] = [];
            $columnArr['key'] = null;
            if ($columnArr['indexes'] = $table->getColumnsIndexes($columnArr['name'], true)) {
                // Convert indexes to Array
                foreach ($columnArr['indexes'] as $name => $index) {
                    $columnArr['indexes'][$name] = Index::toArray($index);
                }

                // If there are multiple indexes for the column
                // the Key will be one with highest priority
                $indexType = array_values($columnArr['indexes'])[0]['type'];
                $columnArr['key'] = substr($indexType, 0, 3);
            }

            return $columnArr;
        });
    }

    public static function listTableColumnNames($tableName)
    {
        Type::registerCustomPlatformTypes();

        $columnNames = [];

        foreach (static::manager()->listTableColumns($tableName) as $column) {
            $columnNames[] = $column->getName();
        }

        return $columnNames;
    }

    public static function createTable($table)
    {
        if (!($table instanceof DoctrineTable)) {
            $table = Table::make($table);
        }

        static::manager()->createTable($table);
    }

    public static function getDoctrineTable($table)
    {
        $table = trim($table);

        if (!static::tableExists($table)) {
            throw SchemaException::tableDoesNotExist($table);
        }

        return static::manager()->listTableDetails($table);
    }

    public static function getDoctrineColumn($table, $column)
    {
        return static::getDoctrineTable($table)->getColumn($column);
    }
}
