<?php

declare(strict_types=1);

namespace Zana\Database;

use Zana\Ddd\Domain\UnitOfWorkInterface;

/**
 * A unit of work backed by a database transaction.
 */
final readonly class TransactionalUnitOfWork implements UnitOfWorkInterface
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function begin(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        $this->connection->rollBack();
    }

    public function transactional(callable $callback): mixed
    {
        return $this->connection->transaction(static fn (): mixed => $callback());
    }
}
