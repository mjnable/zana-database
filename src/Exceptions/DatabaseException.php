<?php

declare(strict_types=1);

namespace Zana\Database\Exceptions;

/**
 * Base exception for database package errors.
 */
class DatabaseException extends \RuntimeException
{
    /**
     * Create a new database exception.
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Database error',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

