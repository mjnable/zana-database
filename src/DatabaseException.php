<?php

declare(strict_types=1);

namespace Zana\Database;

/**
 * Thrown when a query or connection fails. Carries the SQL, never the parameter values.
 */
class DatabaseException extends \RuntimeException
{
    public ?string $sql = null;

    public static function query(string $sql, \Throwable $previous): self
    {
        $exception = new self("Query failed: {$previous->getMessage()} [SQL: {$sql}]", 0, $previous);
        $exception->sql = $sql;

        return $exception;
    }

    /**
     * True for a deadlock or a lock wait timeout (ADR-027): running the transaction again can succeed.
     */
    public function isRetryable(): bool
    {
        return $this->getPrevious() instanceof \Doctrine\DBAL\Exception\RetryableException;
    }
}
