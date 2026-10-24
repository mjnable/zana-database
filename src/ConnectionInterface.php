<?php

declare(strict_types=1);

namespace Zana\Database;

use Zana\Database\Grammar\Grammar;
use Zana\Database\Query\QueryBuilder;
use Zana\Database\Schema\Schema;

/**
 * A database connection. Parameters may be positional (?) or named (:name).
 */
interface ConnectionInterface
{
    /**
     * @param array<array-key, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * @param array<array-key, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $bindings = []): ?array;

    /**
     * The first column of the first row, or null.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed;

    /**
     * @param array<array-key, mixed> $bindings
     * @return int Affected rows
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * @param array<string, mixed> $values
     * @return string The last insert id ('' when the driver has none)
     */
    public function insert(string $table, array $values): string;

    /**
     * Run the callback in a transaction (a savepoint when nested); commit, or roll back and rethrow.
     *
     * @template T
     * @param callable(ConnectionInterface): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function transactionLevel(): int;

    public function table(string $table): QueryBuilder;

    public function schema(): Schema;

    public function grammar(): Grammar;

    /**
     * "sqlite", "mysql" or "pgsql".
     */
    public function driver(): string;

    public function pdo(): \PDO;
}
