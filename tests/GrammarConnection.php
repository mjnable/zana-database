<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use Zana\Database\Connection;
use Zana\Database\ConnectionInterface;
use Zana\Database\Grammar\Grammar;
use Zana\Database\Query\QueryBuilder;
use Zana\Database\Schema\Schema;

/**
 * An SQLite connection that reports another grammar, to assert the SQL
 * generated for MySQL and PostgreSQL without those servers.
 */
final readonly class GrammarConnection implements ConnectionInterface
{
    public function __construct(private Connection $inner, private Grammar $grammar)
    {
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->inner->select($sql, $bindings);
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        return $this->inner->first($sql, $bindings);
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        return $this->inner->scalar($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->inner->execute($sql, $bindings);
    }

    public function insert(string $table, array $values): string
    {
        return $this->inner->insert($table, $values);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->inner->transaction(fn (): mixed => $callback($this));
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function transactionLevel(): int
    {
        return $this->inner->transactionLevel();
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    public function schema(): Schema
    {
        return new Schema($this);
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    public function driver(): string
    {
        return $this->inner->driver();
    }

    public function pdo(): \PDO
    {
        return $this->inner->pdo();
    }
}
