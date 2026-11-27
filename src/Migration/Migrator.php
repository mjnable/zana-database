<?php

declare(strict_types=1);

namespace Zana\Database\Migration;

use Doctrine\Common\EventManager;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\EventDispatcher;
use Doctrine\Migrations\Events;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\MigrationsRepository;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zana\Database\ConnectionInterface;
use Zana\Database\DbalConnection;
use Zana\Database\Migration\Doctrine\Chronological;
use Zana\Database\Migration\Doctrine\DryRuns;
use Zana\Database\Migration\Doctrine\ImplicitCommits;
use Zana\Database\Migration\Doctrine\Messages;
use Zana\Database\Migration\Doctrine\Migrations;
use Zana\Database\Migration\Doctrine\MigrationTable;

/**
 * Runs the migrations of several directories, in the order of the date in their name, on Doctrine Migrations
 * (ADR-035). Two kinds live side by side:
 *
 * - Zana files, YYYY_MM_DD_HHMMSS_description.php returning a Migration, as before 2.37;
 * - Doctrine classes, VersionYYYYMMDDHHMMSS.php extending Doctrine\Migrations\AbstractMigration.
 *
 * zana_migrations stays the record, so what ran before 2.37 is known. migrate() runs the pending migrations as one
 * batch, and rollback() reverts the last batches, newest first.
 *
 * Transactions: where DDL is transactional (SQLite, PostgreSQL) a batch runs in one transaction when every migration
 * in it is transactional. Otherwise each transactional migration (a Doctrine class, by default) runs in its own,
 * with its row in zana_migrations. On MySQL and MariaDB a schema change commits the transaction: a Zana file runs
 * without one, and a failed migration leaves the earlier ones applied.
 *
 * A rollback over a migration with no down() is refused before anything runs. On a connection that is not a
 * DbalConnection, the migrator of 2.36 runs Zana files only.
 */
final class Migrator
{
    private ?DependencyFactory $doctrine = null;
    private ?Migrations $migrations = null;
    private ?MigrationTable $table = null;
    private ?Messages $messages = null;
    private ?ImplicitCommits $commits = null;
    private ?PdoMigrator $pdo = null;

    /**
     * @param list<string> $paths Directories, e.g. database/migrations and each module's migrations
     * @param LoggerInterface $logger Where Doctrine's records and what migrations say also go
     * @param int $lockTimeout Seconds a run waits for another on the same database (FE-85); 0 does not wait
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly MigrationRepository $repository,
        private readonly array $paths,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $lockTimeout = 60,
    ) {
        new MigrationLock($connection, $lockTimeout);   // a negative timeout is refused here, not at the first run
        if (!$connection instanceof DbalConnection) {
            $this->pdo = new PdoMigrator($connection, $repository, $paths, $lockTimeout);
        }
    }

    /**
     * Run every pending migration as one new batch. Two runs on one database are serialized: what is pending is read
     * once the lock is held, so a run that waited for another finds what that one left.
     *
     * @return list<string> The migrations that ran
     */
    public function migrate(): array
    {
        if ($this->pdo !== null) {
            return $this->pdo->migrate();
        }

        return $this->lock()->hold(function (): array {
            $this->files();
            $this->table()->ensureInitialized();
            $doctrine = $this->doctrine();
            $pending = array_map(static fn ($m): Version => $m->getVersion(), $doctrine->getMigrationStatusCalculator()->getNewMigrations()->getItems());
            if ($pending === []) {
                return [];
            }
            $this->table()->startBatch();

            return $this->execute($doctrine->getMigrationPlanCalculator()->getPlanForVersions($pending, Direction::UP));
        });
    }

    /**
     * Revert the last batches, newest migration first, holding the lock as migrate() does.
     *
     * @return list<string> The migrations that were reverted
     */
    public function rollback(int $steps = 1): array
    {
        if ($this->pdo !== null) {
            return $this->pdo->rollback($steps);
        }

        return $this->lock()->hold(function () use ($steps): array {
            $ran = $this->repository->ran();
            if ($ran === []) {
                return [];
            }
            $batches = array_values(array_unique($ran));
            rsort($batches);
            $batches = array_slice($batches, 0, max(1, $steps));

            return $this->revert(array_keys(array_filter($ran, static fn (int $batch): bool => in_array($batch, $batches, true))));
        });
    }

    /**
     * Revert every migration, under one lock from the first down() to the last.
     *
     * @return list<string>
     */
    public function reset(): array
    {
        if ($this->pdo !== null) {
            return $this->pdo->reset();
        }

        return $this->lock()->hold(fn (): array => $this->revert(array_keys($this->repository->ran())));
    }

    private function lock(): MigrationLock
    {
        return new MigrationLock($this->connection, $this->lockTimeout);
    }

    /**
     * @return list<array{migration: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        if ($this->pdo !== null) {
            return $this->pdo->status();
        }
        $ran = $this->repository->ran();
        $names = array_values(array_unique([...array_keys($this->files()), ...array_keys($ran)]));
        $order = new Chronological();
        usort($names, static fn (string $a, string $b): int => $order->compare(new Version($a), new Version($b)));

        return array_map(static fn (string $name): array => [
            'migration' => $name,
            'ran' => isset($ran[$name]),
            'batch' => $ran[$name] ?? null,
        ], $names);
    }

    /**
     * Migration files from every path, by name: a Zana file by its file name, a Doctrine class by its class name.
     *
     * @return array<string, string> name => path
     */
    public function files(): array
    {
        if ($this->pdo !== null) {
            return $this->pdo->files();
        }
        $this->migrations()->refresh();

        return $this->migrations()->files();
    }

    /**
     * The migrations that cannot be rolled back: Doctrine classes that do not override down(), such as the data
     * migrations make:migration --data writes.
     *
     * @return list<string>
     */
    public function irreversible(): array
    {
        if ($this->pdo !== null) {
            return [];
        }
        $this->files();
        $names = [];
        foreach ($this->migrations()->getMigrations()->getItems() as $migration) {
            if (self::withoutDown($migration->getMigration())) {
                $names[] = (string) $migration->getVersion();
            }
        }

        return $names;
    }

    /**
     * What the migrations of the last operations said (write(), warnIf()), a skipped migration and its reason, and
     * the migrator's warnings; each is returned once.
     *
     * @return list<array{level: string, message: string}>
     */
    public function messages(): array
    {
        return $this->messages?->take() ?? [];
    }

    /**
     * @internal Doctrine's services on this connection, with Zana's record, finder, order and safeguards. Zana exposes
     * no Doctrine command (ADR-035): this is for its own integrations and tests.
     */
    public function doctrine(): DependencyFactory
    {
        if ($this->doctrine !== null) {
            return $this->doctrine;
        }
        if (!$this->connection instanceof DbalConnection) {
            throw new MigrationException('Doctrine Migrations run on the DBAL connection that ConnectionFactory::create() makes.');
        }
        $dbal = $this->connection->dbal();
        $configuration = new Configuration();
        $configuration->setTransactional(true);
        $configuration->setCheckDatabasePlatform(false);
        $this->messages = new Messages($this->logger);
        $doctrine = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($dbal), $this->messages);
        $this->migrations = new Migrations($this->paths, $dbal, $this->connection, $this->messages);
        $this->table = new MigrationTable($this->repository);
        $this->commits = new ImplicitCommits($dbal, $this->messages);
        $events = new EventManager();
        $events->addEventListener([Events::onMigrationsVersionExecuted, Events::onMigrationsVersionSkipped], $this->commits);
        $events->addEventListener(Events::onMigrationsVersionExecuting, new DryRuns());
        $doctrine->setService(EventDispatcher::class, new EventDispatcher($dbal, $events));
        $doctrine->setService(MigrationsRepository::class, $this->migrations);
        $doctrine->setService(MetadataStorage::class, $this->table);
        $doctrine->setService(Comparator::class, new Chronological());

        return $this->doctrine = $doctrine;
    }

    /**
     * @param list<string> $names
     * @return list<string> newest first
     */
    private function revert(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $files = $this->files();
        foreach ($names as $name) {
            if (!isset($files[$name])) {
                throw new MigrationException("Cannot roll back {$name}: its file is missing.");
            }
        }
        $plan = $this->doctrine()->getMigrationPlanCalculator()->getPlanForVersions(array_map(static fn (string $v): Version => new Version($v), $names), Direction::DOWN);
        // Before any down() runs: on MySQL a batch half rolled back cannot be put back.
        foreach ($plan->getItems() as $item) {
            if (self::withoutDown($item->getMigration())) {
                throw new MigrationException("Cannot roll back {$item->getVersion()}: it has no down(), so it cannot be undone. Nothing was rolled back.");
            }
        }

        return $this->execute($plan);
    }

    /**
     * @return list<string> The migrations that ran, without those skipped (skipIf())
     */
    private function execute(MigrationPlanList $plan): array
    {
        // Doctrine's all-or-nothing transaction only where DDL is transactional: on MySQL a schema change commits it, and
        // the savepoint of the migration with it, so a failure would be reported as "SAVEPOINT does not exist".
        $allOrNothing = $this->connection->grammar()->supportsTransactionalDdl();
        foreach ($plan->getItems() as $item) {
            $allOrNothing = $allOrNothing && $item->getMigration()->isTransactional();
        }
        try {
            $this->doctrine()->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing($allOrNothing));
        } catch (MigrationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $failed = '?';
            foreach ($plan->getItems() as $item) {
                if ($item->getResult() === null || $item->getResult()->hasError()) {
                    $failed = (string) $item->getVersion();
                    break;
                }
            }
            $kept = $this->commits?->committed($failed) === true ? ' Its statements before the schema change stay applied: on this database a schema change commits the transaction.' : '';
            throw new MigrationException("Migration {$failed} failed ({$plan->getDirection()}): {$e->getMessage()}{$kept}", 0, $e);
        }

        $ran = [];
        foreach ($plan->getItems() as $item) {
            if ($item->getResult()?->isSkipped() !== true) {
                $ran[] = (string) $item->getVersion();
            }
        }

        return $ran;
    }

    private static function withoutDown(AbstractMigration $migration): bool
    {
        return (new \ReflectionMethod($migration, 'down'))->getDeclaringClass()->getName() === AbstractMigration::class;
    }

    private function migrations(): Migrations
    {
        $this->doctrine();
        assert($this->migrations !== null);

        return $this->migrations;
    }

    private function table(): MigrationTable
    {
        $this->doctrine();
        assert($this->table !== null);

        return $this->table;
    }
}
