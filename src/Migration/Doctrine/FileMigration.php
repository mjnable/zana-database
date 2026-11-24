<?php

declare(strict_types=1);

namespace Zana\Database\Migration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\LoggerInterface;
use Zana\Database\ConnectionInterface;
use Zana\Database\Migration\Migration;
use Zana\Database\Migration\MigrationException;

/**
 * @internal A Zana migration file (YYYY_MM_DD_HHMMSS_name.php returning a Migration), run unchanged by Doctrine
 * Migrations (ADR-035).
 *
 * Its schema builder runs each statement at once, so it is transactional only where DDL is, as in 2.36 and before.
 */
final class FileMigration extends AbstractMigration
{
    private ?Migration $migration = null;

    public function __construct(Connection $connection, LoggerInterface $logger, private readonly ConnectionInterface $zana, private readonly string $path)
    {
        parent::__construct($connection, $logger);
    }

    public function getDescription(): string
    {
        return $this->path;
    }

    public function isTransactional(): bool
    {
        return $this->zana->grammar()->supportsTransactionalDdl();
    }

    public function up(DoctrineSchema $schema): void
    {
        $this->migration()->up($this->zana->schema());
    }

    public function down(DoctrineSchema $schema): void
    {
        $this->migration()->down($this->zana->schema());
    }

    private function migration(): Migration
    {
        if ($this->migration === null) {
            $migration = (static fn (string $file): mixed => require $file)($this->path);
            if (!$migration instanceof Migration) {
                throw new MigrationException("{$this->path} must return an instance of " . Migration::class . '.');
            }
            $this->migration = $migration;
        }

        return $this->migration;
    }
}
