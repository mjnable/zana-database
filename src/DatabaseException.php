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
     * A value that can be bound: a scalar, null, a resource (a LOB stream), or
     * a Stringable as its string (2.20, S33). An array or another object was
     * bound as the text "Array" with only a warning, so a form field sent as
     * email[]=x stored "Array", and where('token', $value) compared with it.
     *
     * @internal
     * @throws self For an array or an object that is not Stringable
     */
    public static function bindable(int|string $key, mixed $value, string $sql): mixed
    {
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_array($value) || is_object($value)) {
            $exception = new self(sprintf('Cannot bind %s to parameter %s: a value must be a string, a number, a bool or null. [SQL: %s]', get_debug_type($value), is_int($key) ? '#' . ($key + 1) : ':' . ltrim($key, ':'), $sql));
            $exception->sql = $sql;
            throw $exception;
        }

        return $value;
    }

    /**
     * True for a deadlock or a lock wait timeout (ADR-027): running the transaction again can succeed.
     */
    public function isRetryable(): bool
    {
        return $this->getPrevious() instanceof \Doctrine\DBAL\Exception\RetryableException;
    }
}
