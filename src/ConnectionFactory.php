<?php

declare(strict_types=1);

namespace Zana\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Opens connections from configuration arrays.
 *
 *     ['driver' => 'sqlite', 'database' => 'var/bmi.sqlite']           // or ':memory:'
 *     ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'bmi', 'username' => 'bmi', 'password' => '...']
 *     ['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'database' => 'bmi', 'username' => 'bmi', 'password' => '...']
 *
 * Since 2.12 the connection runs on Doctrine DBAL (ADR-027).
 */
final class ConnectionFactory
{
    /**
     * Open a connection on Doctrine DBAL. It connects at once, so wrong settings fail here.
     *
     * @param array<string, mixed> $config
     * @throws DatabaseException
     */
    public static function create(array $config): ConnectionInterface
    {
        $driver = (string) ($config['driver'] ?? '');
        $string = self::reader($config);

        $params = match ($driver) {
            'sqlite' => ['driver' => 'pdo_sqlite', ...($string('database', ':memory:') === ':memory:' ? ['memory' => true] : ['path' => $string('database')])],
            'mysql' => ['driver' => 'pdo_mysql', 'host' => $string('host', '127.0.0.1'), 'port' => (int) $string('port', '3306'), 'dbname' => $string('database'), 'charset' => $string('charset', 'utf8mb4')],
            'pgsql' => ['driver' => 'pdo_pgsql', 'host' => $string('host', '127.0.0.1'), 'port' => (int) $string('port', '5432'), 'dbname' => $string('database')],
            default => throw new DatabaseException("Unknown database driver: \"{$driver}\". Use sqlite, mysql or pgsql."),
        };
        if ($driver !== 'sqlite') {
            $params += ['user' => $string('username'), 'password' => $string('password'), 'driverOptions' => [\PDO::ATTR_EMULATE_PREPARES => false]];
        }

        try {
            $connection = DriverManager::getConnection($params);
            $connection->getNativeConnection();   // connect now
            if ($driver === 'sqlite') {
                $connection->executeStatement('PRAGMA foreign_keys = ON');
            }
        } catch (DbalException $e) {
            throw new DatabaseException("Cannot connect to the {$driver} database: {$e->getMessage()}", 0, $e);
        }

        return new DbalConnection($connection);
    }

    /**
     * The PDO connection of 2.11 and earlier, with its own grammars.
     *
     * @deprecated since 2.12: use create(); this one goes in 3.0.
     *
     * @param array<string, mixed> $config
     * @throws DatabaseException
     */
    public static function createPdo(array $config): Connection
    {
        $driver = (string) ($config['driver'] ?? '');
        $string = self::reader($config);

        [$dsn, $options] = match ($driver) {
            'sqlite' => ['sqlite:' . $string('database', ':memory:'), []],
            'mysql' => [
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $string('host', '127.0.0.1'), $string('port', '3306'), $string('database'), $string('charset', 'utf8mb4')),
                [\PDO::ATTR_EMULATE_PREPARES => false],
            ],
            'pgsql' => [
                sprintf('pgsql:host=%s;port=%s;dbname=%s', $string('host', '127.0.0.1'), $string('port', '5432'), $string('database')),
                [\PDO::ATTR_EMULATE_PREPARES => false],
            ],
            default => throw new DatabaseException("Unknown database driver: \"{$driver}\". Use sqlite, mysql or pgsql."),
        };

        try {
            $pdo = new \PDO($dsn, $string('username') ?: null, $string('password') ?: null, $options);
        } catch (\PDOException $e) {
            throw new DatabaseException("Cannot connect to the {$driver} database: {$e->getMessage()}", 0, $e);
        }

        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return new Connection($pdo);
    }

    /**
     * @param array<string, mixed> $config
     * @return \Closure(string, string=): string
     */
    private static function reader(array $config): \Closure
    {
        return static fn (string $key, string $default = ''): string => isset($config[$key]) && is_scalar($config[$key]) ? (string) $config[$key] : $default;
    }
}
