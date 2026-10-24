<?php

declare(strict_types=1);

namespace Zana\Database;

/**
 * Opens connections from configuration arrays.
 *
 *     ['driver' => 'sqlite', 'database' => 'var/bmi.sqlite']           // or ':memory:'
 *     ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'bmi', 'username' => 'bmi', 'password' => '...']
 *     ['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'database' => 'bmi', 'username' => 'bmi', 'password' => '...']
 */
final class ConnectionFactory
{
    /**
     * @param array<string, mixed> $config
     * @throws DatabaseException
     */
    public static function create(array $config): Connection
    {
        $driver = (string) ($config['driver'] ?? '');
        $string = static fn (string $key, string $default = ''): string => isset($config[$key]) && is_scalar($config[$key]) ? (string) $config[$key] : $default;

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
}
