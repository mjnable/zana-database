<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

/**
 * The databases the platform tests run on (ADR-027): SQLite always; MySQL
 * and PostgreSQL when they answer, on a dedicated database
 * "zana_database_test" that is dropped and created again. Settings come from
 * ZANA_TEST_MYSQL_HOST, _PORT, _USER, _PASSWORD (default 127.0.0.1, 3306,
 * root, no password) and ZANA_TEST_PGSQL_* (127.0.0.1, 5432, postgres, no
 * password).
 */
final class Drivers
{
    public const string DATABASE = 'zana_database_test';

    /** @var array<string, array<string, mixed>|string>|null */
    private static ?array $drivers = null;

    /**
     * @return array<string, array{string}> name => [name], for data providers
     */
    public static function names(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /**
     * The connection settings, or why the database cannot be used here.
     *
     * @return array<string, mixed>|string
     */
    public static function settings(string $name, ?string $sqliteFile = null): array|string
    {
        if ($name === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => $sqliteFile ?? ':memory:'];
        }
        self::$drivers ??= ['mysql' => self::prepare('mysql'), 'pgsql' => self::prepare('pgsql')];

        return self::$drivers[$name];
    }

    /**
     * @return array<string, mixed>|string
     */
    private static function prepare(string $driver): array|string
    {
        $prefix = $driver === 'mysql' ? 'ZANA_TEST_MYSQL_' : 'ZANA_TEST_PGSQL_';
        $env = static fn (string $name, string $default): string => is_string(getenv($prefix . $name)) ? (string) getenv($prefix . $name) : $default;
        $settings = [
            'driver' => $driver,
            'host' => $env('HOST', '127.0.0.1'),
            'port' => $env('PORT', $driver === 'mysql' ? '3306' : '5432'),
            'username' => $env('USER', $driver === 'mysql' ? 'root' : 'postgres'),
            'password' => $env('PASSWORD', ''),
        ];
        if (!in_array("pdo_{$driver}", array_map('strtolower', get_loaded_extensions()), true)) {
            return "The PHP extension pdo_{$driver} is not loaded.";
        }

        try {
            $server = $driver === 'mysql'
                ? new \PDO("mysql:host={$settings['host']};port={$settings['port']}", $settings['username'], $settings['password'], [\PDO::ATTR_TIMEOUT => 2])
                : new \PDO("pgsql:host={$settings['host']};port={$settings['port']};dbname=postgres", $settings['username'], $settings['password'], [\PDO::ATTR_TIMEOUT => 2]);
            $server->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $server->exec('DROP DATABASE IF EXISTS ' . self::DATABASE);
            $server->exec('CREATE DATABASE ' . self::DATABASE);
        } catch (\PDOException $e) {
            return "{$driver} is not reachable at {$settings['host']}:{$settings['port']}: {$e->getMessage()}";
        }

        return [...$settings, 'database' => self::DATABASE];
    }
}
