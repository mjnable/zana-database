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
}
