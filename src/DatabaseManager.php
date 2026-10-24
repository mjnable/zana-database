<?php

declare(strict_types=1);

namespace Zana\Database;

use Zana\Configuration\ConfigurationInterface;

/**
 * Opens and caches named connections from the "database" configuration.
 *
 *     'database' => [
 *         'default' => 'main',
 *         'connections' => ['main' => ['driver' => 'sqlite', 'database' => 'var/bmi.sqlite']],
 *     ]
 *
 * A relative SQLite path is relative to the base path.
 */
final class DatabaseManager
{
    /** @var array<string, ConnectionInterface> */
    private array $connections = [];

    public function __construct(
        private readonly ConfigurationInterface $config,
        private readonly string $basePath,
    ) {
    }

    public function defaultConnection(): string
    {
        return $this->config->string('database.default', 'main');
    }

    /**
     * @throws DatabaseException For an unknown connection
     */
    public function connection(?string $name = null): ConnectionInterface
    {
        $name ??= $this->defaultConnection();

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        /** @var array<string, mixed> $settings */
        $settings = $this->config->array("database.connections.{$name}", []);
        if ($settings === []) {
            throw new DatabaseException("Database connection \"{$name}\" is not configured.");
        }

        $database = $settings['database'] ?? null;
        if (($settings['driver'] ?? null) === 'sqlite' && is_string($database) && $database !== ':memory:' && !self::isAbsolute($database)) {
            $settings['database'] = rtrim($this->basePath, '/') . '/' . $database;
        }

        return $this->connections[$name] = ConnectionFactory::create($settings);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
