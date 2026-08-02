<?php

declare(strict_types=1);

namespace Zana\Database\Connections;

use Zana\Database\Contracts\ConnectionInterface;
use Zana\Database\Exceptions\DatabaseException;

/**
 * PDO-based database connection.
 *
 * Supports MySQL, PostgreSQL, SQLite, and SQL Server via PDO driver.
 * Uses prepared statements for all queries to prevent SQL injection.
 */
class PDOConnection implements ConnectionInterface
{
    private ?\PDO $pdo = null;

    private string $dsn;

    private string $username;

    private string $password;

    private array $options;

    private int $transactionDepth = 0;

    private string $driver;

    /**
     * @param string $dsn The PDO DSN string
     * @param string $username Database username
     * @param string $password Database password
     * @param array<int, mixed> $options Additional PDO options
     * @param string|null $driver Optional driver name; inferred from DSN when null
     */
    public function __construct(
        string $dsn,
        string $username = '',
        string $password = '',
        array $options = [],
        ?string $driver = null
    ) {
        $this->dsn = $dsn;
        $this->driver = $driver ?? $this->inferDriver($dsn);
        $this->username = $username;
        $this->password = $password;
        $this->options = $options + [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
    }

    /**
     * Create a MySQL connection from configuration array.
     *
     * @param array<string, mixed> $config
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        $driver = $config['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $database = $config['database'] ?? ':memory:';

            $dsn = $database === ':memory:'
                ? 'sqlite::memory:'
                : 'sqlite:' . $database;

            return new self(
                $dsn,
                '',
                '',
                $config['options'] ?? [],
                'sqlite'
            );
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $driver,
            $host,
            (int) $port,
            $database,
            $charset
        );

        return new self(
            $dsn,
            $config['username'] ?? '',
            $config['password'] ?? '',
            $config['options'] ?? [],
            $driver
        );
    }

    /**
     * Get or create the PDO connection.
     */
    private function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new \PDO(
                    $this->dsn,
                    $this->username,
                    $this->password,
                    $this->options
                );
            } catch (\PDOException $e) {
                throw new DatabaseException(
                    'Database connection failed: ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        }

        return $this->pdo;
    }

    /**
     * Execute a query and return all result rows.
     *
     * @param string $sql The SQL statement
     * @param array<int, mixed> $params Bound parameters
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $statement = $this->getPdo()->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll();
        } catch (\PDOException $e) {
            throw new DatabaseException(
                'Query failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Execute a statement and return the number of affected rows.
     *
     * @param string $sql The SQL statement
     * @param array<int, mixed> $params Bound parameters
     * @return int Number of affected rows
     * @throws DatabaseException
     */
    public function execute(string $sql, array $params = []): int
    {
        try {
            $statement = $this->getPdo()->prepare($sql);
            $statement->execute($params);
            return $statement->rowCount();
        } catch (\PDOException $e) {
            throw new DatabaseException(
                'Execute failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Begin a database transaction.
     * Supports nested transactions via savepoints.
     */
    public function beginTransaction(): bool
    {
        try {
            if ($this->transactionDepth === 0) {
                $result = $this->getPdo()->beginTransaction();
            } else {
                $result = $this->getPdo()->exec(
                    sprintf('SAVEPOINT trans%d', $this->transactionDepth)
                ) !== false;
            }

            if ($result) {
                $this->transactionDepth++;
            }

            return $result;
        } catch (\PDOException $e) {
            throw new DatabaseException(
                'Failed to begin transaction: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): bool
    {
        try {
            if ($this->transactionDepth === 1) {
                $result = $this->getPdo()->commit();
            } elseif ($this->transactionDepth > 1) {
                $result = $this->getPdo()->exec(
                    sprintf('RELEASE SAVEPOINT trans%d', $this->transactionDepth - 1)
                ) !== false;
            } else {
                return false;
            }

            if ($result) {
                $this->transactionDepth--;
            }

            return $result;
        } catch (\PDOException $e) {
            throw new DatabaseException(
                'Failed to commit transaction: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Roll back the current transaction.
     */
    public function rollback(): bool
    {
        try {
            if ($this->transactionDepth === 1) {
                $result = $this->getPdo()->rollBack();
            } elseif ($this->transactionDepth > 1) {
                $result = $this->getPdo()->exec(
                    sprintf('ROLLBACK TO SAVEPOINT trans%d', $this->transactionDepth - 1)
                ) !== false;
            } else {
                return false;
            }

            if ($result) {
                $this->transactionDepth--;
            }

            return $result;
        } catch (\PDOException $e) {
            throw new DatabaseException(
                'Failed to roll back transaction: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get the last inserted auto-increment ID.
     *
     * @return string|int
     */
    public function lastInsertId(): string|int
    {
        try {
            return $this->getPdo()->lastInsertId();
        } catch (\PDOException $e) {
            throw new DatabaseException(
                'Failed to get last insert ID: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get the database driver name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Check if a connection is active.
     */
    public function isConnected(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Close the connection by destroying the PDO instance.
     */
    public function close(): void
    {
        $this->pdo = null;
        $this->transactionDepth = 0;
    }

    /**
     * Infer the PDO driver name from a DSN string.
     */
    private function inferDriver(string $dsn): string
    {
        $separator = strpos($dsn, ':');

        return $separator === false ? 'unknown' : substr($dsn, 0, $separator);
    }
}

