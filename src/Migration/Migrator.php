<?php

declare(strict_types=1);

namespace Zana\Database\Migration;

use Zana\Database\ConnectionInterface;

/**
 * Runs migrations from several directories in file-name order.
 *
 * A batch runs in one transaction where the driver supports transactional
 * DDL (SQLite, PostgreSQL); on MySQL a failed migration leaves earlier ones applied.
 */
final readonly class Migrator
{
    private const FILE_NAME = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/';

    /**
     * @param list<string> $paths Directories, e.g. database/migrations and each module's migrations
     */
    public function __construct(
        private ConnectionInterface $connection,
        private MigrationRepository $repository,
        private array $paths,
    ) {
    }

    /**
     * Run every pending migration as one new batch.
     *
     * @return list<string> The migrations that ran
     */
    public function migrate(): array
    {
        $ran = $this->repository->ran();
        $pending = array_diff_key($this->files(), $ran);
        if ($pending === []) {
            return [];
        }

        $batch = $this->repository->lastBatch() + 1;

        $this->inTransaction(function () use ($pending, $batch): void {
            foreach ($pending as $name => $path) {
                $this->run($name, $path, 'up');
                $this->repository->log($name, $batch);
            }
        });

        return array_keys($pending);
    }

    /**
     * Revert the last batches, newest migration first.
     *
     * @return list<string> The migrations that were reverted
     */
    public function rollback(int $steps = 1): array
    {
        $ran = $this->repository->ran();
        if ($ran === []) {
            return [];
        }

        $batches = array_values(array_unique($ran));
        rsort($batches);
        $batches = array_slice($batches, 0, max(1, $steps));

        $revert = array_keys(array_filter($ran, static fn (int $batch): bool => in_array($batch, $batches, true)));
        rsort($revert);

        return $this->revert($revert);
    }

    /**
     * Revert every migration.
     *
     * @return list<string>
     */
    public function reset(): array
    {
        $revert = array_keys($this->repository->ran());
        rsort($revert);

        return $this->revert($revert);
    }

    /**
     * @return list<array{migration: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        $ran = $this->repository->ran();
        $names = array_unique([...array_keys($this->files()), ...array_keys($ran)]);
        sort($names);

        return array_map(static fn (string $name): array => [
            'migration' => $name,
            'ran' => isset($ran[$name]),
            'batch' => $ran[$name] ?? null,
        ], $names);
    }

    /**
     * Migration files from every path, by name, sorted.
     *
     * @return array<string, string> name => path
     */
    public function files(): array
    {
        $files = [];
        foreach ($this->paths as $path) {
            foreach (glob(rtrim($path, '/\\') . '/*.php') ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (preg_match(self::FILE_NAME, $name) !== 1) {
                    throw new MigrationException("Migration file {$file} must be named YYYY_MM_DD_HHMMSS_description.php (lower-case, underscores).");
                }
                if (isset($files[$name])) {
                    throw new MigrationException("Migration {$name} exists in two directories: {$files[$name]} and {$file}.");
                }
                $files[$name] = $file;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function revert(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $files = $this->files();
        $this->inTransaction(function () use ($names, $files): void {
            foreach ($names as $name) {
                if (!isset($files[$name])) {
                    throw new MigrationException("Cannot roll back {$name}: its file is missing.");
                }
                $this->run($name, $files[$name], 'down');
                $this->repository->delete($name);
            }
        });

        return $names;
    }

    /**
     * @param 'up'|'down' $direction
     */
    private function run(string $name, string $path, string $direction): void
    {
        $migration = (static fn (string $file): mixed => require $file)($path);
        if (!$migration instanceof Migration) {
            throw new MigrationException("{$path} must return an instance of " . Migration::class . '.');
        }

        try {
            $migration->{$direction}($this->connection->schema());
        } catch (MigrationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MigrationException("Migration {$name} failed ({$direction}): {$e->getMessage()}", 0, $e);
        }
    }

    private function inTransaction(\Closure $work): void
    {
        if (!$this->connection->grammar()->supportsTransactionalDdl()) {
            $work();

            return;
        }

        $this->connection->transaction(static fn (): mixed => $work());
    }
}
