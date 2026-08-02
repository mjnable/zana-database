<?php

declare(strict_types=1);

namespace Zana\Database\Migrations;

use Zana\Database\Contracts\ConnectionInterface;

/**
 * Manages the tracking table used to record which migrations have been run.
 *
 * Supports both MySQL and SQLite via driver detection on the connection.
 */
class MigrationRepository
{
    public const TABLE = 'migrations';

    private ConnectionInterface $connection;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Create the migrations tracking table if it does not exist.
     */
    public function ensureTable(): void
    {
        if ($this->connection->getDriver() === 'sqlite') {
            $sql = 'CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL,
                batch INTEGER NOT NULL
            )';
        } else {
            $sql = 'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        $this->connection->execute($sql);
    }

    /**
     * Get the names of all migrations that have been run.
     *
     * @return array<int, string>
     */
    public function getRan(): array
    {
        $this->ensureTable();

        $rows = $this->connection->query(
            'SELECT migration FROM migrations ORDER BY id ASC'
        );

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $rows
        );
    }

    /**
     * Get the highest batch number (0 when no migrations have run).
     */
    public function getLastBatchNumber(): int
    {
        $this->ensureTable();

        $rows = $this->connection->query(
            'SELECT COALESCE(MAX(batch), 0) AS batch FROM migrations'
        );

        return (int) ($rows[0]['batch'] ?? 0);
    }

    /**
     * Get the batch number to use for the next set of migrations.
     */
    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    /**
     * Get the names of migrations that belong to a given batch.
     *
     * @return array<int, string>
     */
    public function getMigrationsByBatch(int $batch): array
    {
        $this->ensureTable();

        $rows = $this->connection->query(
            'SELECT migration FROM migrations WHERE batch = ? ORDER BY id ASC',
            [$batch]
        );

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $rows
        );
    }

    /**
     * Record a migration as run.
     */
    public function add(string $migration, int $batch): void
    {
        $this->connection->execute(
            'INSERT INTO migrations (migration, batch) VALUES (?, ?)',
            [$migration, $batch]
        );
    }

    /**
     * Remove a migration from the tracking table.
     */
    public function delete(string $migration): void
    {
        $this->connection->execute(
            'DELETE FROM migrations WHERE migration = ?',
            [$migration]
        );
    }
}

