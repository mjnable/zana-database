<?php

declare(strict_types=1);

namespace Zana\Database\Schema;

use Closure;
use Zana\Database\ConnectionInterface;

/**
 * Creates and drops tables.
 */
final readonly class Schema
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * @param Closure(Blueprint): mixed $define
     */
    public function create(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);

        foreach ($this->connection->grammar()->compileCreate($blueprint) as $statement) {
            $this->connection->execute($statement);
        }
    }

    public function drop(string $table): void
    {
        $this->connection->execute($this->connection->grammar()->compileDrop($table, false));
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->execute($this->connection->grammar()->compileDrop($table, true));
    }

    public function hasTable(string $table): bool
    {
        [$sql, $bindings] = $this->connection->grammar()->compileHasTable($table);

        return $this->connection->first($sql, $bindings) !== null;
    }

    public function hasColumn(string $table, string $column): bool
    {
        [$sql, $bindings] = $this->connection->grammar()->compileHasColumn($table, $column);

        return $this->connection->first($sql, $bindings) !== null;
    }
}
