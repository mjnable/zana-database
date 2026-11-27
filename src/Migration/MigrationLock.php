<?php

declare(strict_types=1);

namespace Zana\Database\Migration;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\DriverManager;
use Zana\Database\ConnectionInterface;
use Zana\Database\DbalConnection;

/**
 * @internal Serializes migrate, rollback and reset on one database (FE-85): a second run waits for the first, up to
 * database.migrations.lock_timeout seconds, then stops with nothing run. A run never goes ahead without the lock.
 *
 * - MySQL and MariaDB: GET_LOCK(), named after the current database, so two databases on one server do not share it.
 * - PostgreSQL: an advisory lock keyed by the current database.
 * - Both on a second connection with the same settings: the migrator closes its own connection after a schema change
 *   commits a transaction on MySQL (ImplicitCommits), which would release a lock held on it. A process that dies
 *   releases it with its session. The 2.36 engine, which never closes its connection, takes it on that connection.
 * - SQLite: an exclusive flock() on <database file>.migrate-lock, released when the process ends. An in-memory
 *   database belongs to one process and needs none.
 */
final class MigrationLock
{
    private const int POLL_MICROSECONDS = 200_000;

    private ?DoctrineConnection $own = null;
    private ?\PDO $pdo = null;
    /** @var resource|null */
    private $file = null;
    private string $name = '';
    /** @var array{int, int} */
    private array $key = [0, 0];

    public function __construct(private readonly ConnectionInterface $connection, private readonly int $timeout)
    {
        if ($timeout < 0) {
            throw new MigrationException("database.migrations.lock_timeout must be 0 or more seconds, not {$timeout}.");
        }
    }

    /**
     * Run $work holding the lock. A failure of $work is thrown as it is, also when releasing the lock fails then.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function hold(\Closure $work): mixed
    {
        $this->acquire();
        try {
            $result = $work();
        } catch (\Throwable $e) {
            try {
                $this->release();
            } catch (\Throwable) {
                // The migration's own failure is the one to report; the lock goes with the session or the process.
            }

            throw $e;
        }
        $this->release();

        return $result;
    }

    private function acquire(): void
    {
        try {
            $acquired = match ($this->connection->driver()) {
                'mysql' => $this->mysql(),
                'pgsql' => $this->pgsql(),
                'sqlite' => $this->sqlite(),
                default => throw new MigrationException("Migrations cannot be serialized on the {$this->connection->driver()} driver."),
            };
        } catch (MigrationException $e) {
            $this->release();

            throw $e;
        } catch (\Throwable $e) {
            try {
                $this->release();
            } catch (\Throwable) {
            }

            throw new MigrationException('The migration lock could not be taken: ' . strtok($e->getMessage(), "\n") . '. Nothing was run.', 0, $e);
        }
        if (!$acquired) {
            $this->release();

            throw new MigrationException($this->timeout === 0
                ? 'Another migrate is running on this database, and database.migrations.lock_timeout is 0, so this one does not wait. Nothing was run.'
                : "Another migrate is running on this database: its lock was not released within {$this->timeout} seconds (database.migrations.lock_timeout). Nothing was run.");
        }
    }

    private function mysql(): bool
    {
        $pdo = $this->lockConnection();
        $this->name = 'zana_migrate_' . md5(self::value($pdo, 'SELECT DATABASE()'));
        $statement = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$this->name, $this->timeout]);
        $result = $statement->fetchColumn();
        if ($result === null || $result === false) {
            throw new MigrationException('The migration lock could not be taken: GET_LOCK() returned NULL. Nothing was run.');
        }

        return (int) $result === 1;
    }

    private function pgsql(): bool
    {
        $pdo = $this->lockConnection();
        $this->key = [self::int32('zana_migrate'), self::int32(self::value($pdo, 'SELECT current_database()'))];
        $statement = $pdo->prepare('SELECT pg_try_advisory_lock(?, ?)');
        $deadline = microtime(true) + $this->timeout;
        do {
            $statement->execute($this->key);
            if (in_array($statement->fetchColumn(), [true, 't', 1, '1'], true)) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(self::POLL_MICROSECONDS);
        } while (true);
    }

    private function sqlite(): bool
    {
        $path = '';
        foreach ($this->connection->select('PRAGMA database_list') as $database) {
            if (($database['name'] ?? null) === 'main') {
                $path = (string) ($database['file'] ?? '');
            }
        }
        if ($path === '') {
            return true;   // in memory, or a temporary database: one process
        }
        $file = fopen($path . '.migrate-lock', 'c');
        if ($file === false) {
            throw new MigrationException("The migration lock {$path}.migrate-lock cannot be opened. Nothing was run.");
        }
        $this->file = $file;
        $deadline = microtime(true) + $this->timeout;
        do {
            if (flock($file, LOCK_EX | LOCK_NB)) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(self::POLL_MICROSECONDS);
        } while (true);
    }

    private function release(): void
    {
        try {
            if ($this->pdo !== null && $this->name !== '') {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->name]);
            } elseif ($this->pdo !== null && $this->key !== [0, 0]) {
                $this->pdo->prepare('SELECT pg_advisory_unlock(?, ?)')->execute($this->key);
            }
            if ($this->file !== null) {
                flock($this->file, LOCK_UN);
                fclose($this->file);
            }
        } finally {
            $this->own?->close();
            [$this->own, $this->pdo, $this->file, $this->name, $this->key] = [null, null, null, '', [0, 0]];
        }
    }

    private function lockConnection(): \PDO
    {
        if ($this->connection instanceof DbalConnection) {
            $this->own = DriverManager::getConnection($this->connection->dbal()->getParams());
            $native = $this->own->getNativeConnection();
            if (!$native instanceof \PDO) {
                throw new MigrationException('The migration lock needs a PDO connection.');
            }
            $native->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo = $native;
        } else {
            $this->pdo = $this->connection->pdo();
        }

        return $this->pdo;
    }

    private static function value(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->prepare($sql);
        $statement->execute();
        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            throw new MigrationException("The migration lock needs the database's name, and {$sql} gave none.");
        }

        return $value;
    }

    private static function int32(string $text): int
    {
        $value = crc32($text);

        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }
}
