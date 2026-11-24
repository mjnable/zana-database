<?php

declare(strict_types=1);

namespace Zana\Database\Migration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Event\MigrationsVersionEventArgs;
use Psr\Log\LoggerInterface;

/**
 * @internal On MySQL and MariaDB a schema statement commits the transaction a migration runs in. Doctrine then skips
 * its own commit, and DBAL still counts the transaction as open, so the next migration's transaction would be a
 * savepoint on a connection in autocommit, and its changes would stay when it fails (ADR-035).
 *
 * After each migration, this closes the connection when that happened: DBAL has no other way to reset its count, and
 * reconnects on next use. It says which migration was not atomic.
 */
final class ImplicitCommits
{
    /** @var array<string, true> */
    private array $committed = [];

    public function __construct(private readonly Connection $dbal, private readonly LoggerInterface $logger)
    {
    }

    public function onMigrationsVersionExecuted(MigrationsVersionEventArgs $args): void
    {
        $this->check($args);
    }

    /**
     * Also sent when a migration failed.
     */
    public function onMigrationsVersionSkipped(MigrationsVersionEventArgs $args): void
    {
        $this->check($args);
    }

    /**
     * Whether this migration's transaction was committed by a schema statement.
     */
    public function committed(string $version): bool
    {
        return isset($this->committed[$version]);
    }

    private function check(MigrationsVersionEventArgs $args): void
    {
        if (!$this->dbal->isTransactionActive()) {
            return;
        }
        $native = $this->dbal->getNativeConnection();
        if (!$native instanceof \PDO || $native->inTransaction()) {
            return;
        }
        $this->dbal->close();
        $version = (string) $args->getPlan()->getVersion();
        $this->committed[$version] = true;
        $this->logger->warning(
            "Migration {$version} changed the schema inside its transaction, and on this database a schema change commits it: if the migration also changed data, that change was not atomic. Return false from isTransactional() in a schema migration, and change data in a migration of its own.",
            [Messages::SHOWN => true],
        );
    }
}
