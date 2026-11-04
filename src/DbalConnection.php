<?php

declare(strict_types=1);

namespace Zana\Database;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Zana\Database\Grammar\Grammar;
use Zana\Database\Grammar\PlatformGrammar;
use Zana\Database\Query\QueryBuilder;
use Zana\Database\Schema\Schema;

/**
 * The connection on Doctrine DBAL (ADR-027): the Zana API of the query and
 * schema builders, compiled for the database by DBAL's platform, with DBAL's
 * transactions and savepoints. dbal() gives the DBAL connection to
 * integrations that must share it, such as an outbox or Doctrine ORM.
 */
final class DbalConnection implements ConnectionInterface
{
    private ?PlatformGrammar $grammar = null;

    public function __construct(private readonly DoctrineConnection $connection)
    {
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static fn (DoctrineConnection $c, array $params, array $types): array => $c->executeQuery($sql, $params, $types)->fetchAllAssociative());
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings, static fn (DoctrineConnection $c, array $params, array $types): array|false => $c->executeQuery($sql, $params, $types)->fetchAssociative());

        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings, static fn (DoctrineConnection $c, array $params, array $types): mixed => $c->executeQuery($sql, $params, $types)->fetchOne());

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return (int) $this->run($sql, $bindings, static fn (DoctrineConnection $c, array $params, array $types): int|string => $c->executeStatement($sql, $params, $types));
    }

    public function insert(string $table, array $values): string
    {
        $this->table($table)->insert($values);

        try {
            return (string) $this->connection->lastInsertId();
        } catch (DbalException) {
            return '';   // a table without an auto-increment key
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
        $this->connection->beginTransaction();   // nested levels are savepoints
    }

    public function commit(): void
    {
        $this->assertInTransaction('commit');
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->assertInTransaction('roll back');
        $this->connection->rollBack();
    }

    public function transactionLevel(): int
    {
        return $this->connection->getTransactionNestingLevel();
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
        return $this->grammar ??= new PlatformGrammar($this->connection);
    }

    public function driver(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof SQLitePlatform => 'sqlite',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform => 'pgsql',
            default => strtolower((new \ReflectionClass($platform))->getShortName()),
        };
    }

    /**
     * @deprecated Use the builders, or dbal() for what they do not cover.
     */
    public function pdo(): \PDO
    {
        $native = $this->connection->getNativeConnection();

        return $native instanceof \PDO ? $native : throw new DatabaseException('This connection does not run on PDO.');
    }

    /**
     * The DBAL connection, for integrations that must share this connection and its transactions.
     */
    public function dbal(): DoctrineConnection
    {
        return $this->connection;
    }

    /**
     * @template T
     * @param array<int|string, mixed> $bindings Positional (a list) or named (":name" or "name")
     * @param \Closure(DoctrineConnection, array<int<0, max>|string, mixed>, array<int<0, max>|string, ParameterType>): T $query
     * @return T
     */
    private function run(string $sql, array $bindings, \Closure $query): mixed
    {
        /** @var array<int<0, max>|string, mixed> $params */
        $params = [];
        /** @var array<int<0, max>|string, ParameterType> $types */
        $types = [];
        foreach ($bindings as $key => $value) {
            $name = is_int($key) ? max(0, $key) : ltrim($key, ':');
            $params[$name] = $value;
            $types[$name] = match (true) {
                is_int($value) => ParameterType::INTEGER,
                is_bool($value) => ParameterType::BOOLEAN,
                $value === null => ParameterType::NULL,
                default => ParameterType::STRING,
            };
        }

        try {
            return $query($this->connection, $params, $types);
        } catch (DbalException $e) {
            throw DatabaseException::query($sql, $e);
        }
    }

    private function assertInTransaction(string $action): void
    {
        if ($this->transactionLevel() === 0) {
            throw new DatabaseException("Cannot {$action}: no transaction is active.");
        }
    }
}
