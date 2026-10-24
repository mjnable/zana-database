<?php

declare(strict_types=1);

namespace Zana\Database;

use Zana\Database\Grammar\Grammar;
use Zana\Database\Grammar\MysqlGrammar;
use Zana\Database\Grammar\PostgresGrammar;
use Zana\Database\Grammar\SqliteGrammar;
use Zana\Database\Query\QueryBuilder;
use Zana\Database\Schema\Schema;

/**
 * A PDO connection. Nested transactions use savepoints.
 */
final class Connection implements ConnectionInterface
{
    private int $level = 0;

    private readonly Grammar $grammar;

    private readonly string $driver;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->grammar = match ($this->driver) {
            'sqlite' => new SqliteGrammar(),
            'mysql' => new MysqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            default => throw new DatabaseException("Unsupported database driver: {$this->driver}."),
        };
    }

    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $bindings)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch(\PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    public function insert(string $table, array $values): string
    {
        $this->table($table)->insert($values);

        try {
            return (string) $this->pdo->lastInsertId();
        } catch (\PDOException) {
            return '';
        }
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->level === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $this->savepoint($this->level + 1));
        }

        $this->level++;
    }

    public function commit(): void
    {
        $this->assertInTransaction('commit');

        if ($this->level === 1) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint($this->level));
        }

        $this->level--;
    }

    public function rollBack(): void
    {
        $this->assertInTransaction('roll back');

        if ($this->level === 1) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $this->savepoint($this->level));
        }

        $this->level--;
    }

    public function transactionLevel(): int
    {
        return $this->level;
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
        return $this->driver;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<array-key, mixed> $bindings
     */
    private function run(string $sql, array $bindings): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            foreach ($bindings as $key => $value) {
                $statement->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), $value, match (true) {
                    is_int($value) => \PDO::PARAM_INT,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    $value === null => \PDO::PARAM_NULL,
                    default => \PDO::PARAM_STR,
                });
            }
            $statement->execute();

            return $statement;
        } catch (\PDOException $e) {
            throw DatabaseException::query($sql, $e);
        }
    }

    private function savepoint(int $level): string
    {
        return "zana_savepoint_{$level}";
    }

    private function assertInTransaction(string $action): void
    {
        if ($this->level === 0) {
            throw new DatabaseException("Cannot {$action}: no transaction is active.");
        }
    }
}
