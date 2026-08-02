<?php

declare(strict_types=1);

namespace Zana\Database\Contracts;

/**
 * Connection Interface for database operations.
 *
 * Provides a database-agnostic abstraction for executing queries,
 * transactions, and managing connections.
 */
interface ConnectionInterface
{
    /**
     * Execute a query and return the result set.
     *
     * @param string $sql The SQL statement
     * @param array<int, mixed> $params Bound parameters
     * @return array<int, array<string, mixed>> Array of result rows
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute a statement and return the number of affected rows.
     *
     * @param string $sql The SQL statement
     * @param array<int, mixed> $params Bound parameters
     * @return int Number of affected rows
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): bool;

    /**
     * Commit the current transaction.
     */
    public function commit(): bool;

    /**
     * Roll back the current transaction.
     */
    public function rollback(): bool;

    /**
     * Get the last inserted auto-increment ID.
     *
     * @return string|int
     */
    public function lastInsertId(): string|int;

    /**
     * Get the database driver name (e.g. "mysql", "sqlite", "pgsql").
     */
    public function getDriver(): string;

    /**
     * Check if a connection is active.
     */
    public function isConnected(): bool;

    /**
     * Close the connection.
     */
    public function close(): void;
}

