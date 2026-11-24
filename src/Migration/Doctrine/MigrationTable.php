<?php

declare(strict_types=1);

namespace Zana\Database\Migration\Doctrine;

use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Query\Query;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use Zana\Database\Migration\MigrationRepository;

/**
 * @internal Doctrine's record of what ran, kept in Zana's zana_migrations table (migration, batch, ran_at), so the
 * history of every application carries over unchanged and a rollback still undoes a batch (ADR-035).
 *
 * Doctrine calls complete() before it commits a transactional migration, so a migration and its row are atomic.
 */
final class MigrationTable implements MetadataStorage
{
    private ?int $batch = null;

    public function __construct(private readonly MigrationRepository $repository)
    {
    }

    /**
     * The migrations that follow are recorded in the batch after the last one.
     */
    public function startBatch(): void
    {
        $this->batch = $this->repository->lastBatch() + 1;
    }

    /**
     * @return array<string, int> migration => batch
     */
    public function batches(): array
    {
        return $this->repository->ran();
    }

    public function ensureInitialized(): void
    {
        $this->repository->ensureTable();
    }

    public function getExecutedMigrations(): ExecutedMigrationsList
    {
        $ran = [];
        foreach ($this->repository->ran() as $name => $batch) {
            $ran[] = new ExecutedMigration(new Version($name));
        }

        return new ExecutedMigrationsList($ran);
    }

    public function complete(ExecutionResult $result): void
    {
        $name = (string) $result->getVersion();
        if ($result->getDirection() === Direction::DOWN) {
            $this->repository->delete($name);

            return;
        }
        if ($this->batch === null) {
            $this->startBatch();
        }
        $this->repository->log($name, (int) $this->batch);
    }

    public function reset(): void
    {
        foreach (array_keys($this->repository->ran()) as $name) {
            $this->repository->delete($name);
        }
    }

    /**
     * Doctrine's --write-sql and dry runs, which Zana does not expose (ADR-035).
     *
     * @return iterable<Query>
     */
    public function getSql(ExecutionResult $result): iterable
    {
        return [];
    }
}
