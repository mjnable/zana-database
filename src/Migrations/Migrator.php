<?php

declare(strict_types=1);

namespace Zana\Database\Migrations;

use Zana\Database\Contracts\ConnectionInterface;
use Zana\Database\Exceptions\MigrationException;

/**
 * Runs and manages database migrations.
 *
 * Migration files live in a migrations directory and follow the naming
 * convention `YYYY_MM_DD_HHMMSS_name.php`. Each file must return an
 * anonymous class extending {@see Migration}.
 */
class Migrator
{
    private ConnectionInterface $connection;

    private MigrationRepository $repository;

    private string $migrationPath;

    public function __construct(
        ConnectionInterface $connection,
        MigrationRepository $repository,
        string $migrationPath
    ) {
        $this->connection = $connection;
        $this->repository = $repository;
        $this->migrationPath = rtrim($migrationPath, '/\\');
    }

    /**
     * Get the underlying database connection.
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Get the migration repository.
     */
    public function getRepository(): MigrationRepository
    {
        return $this->repository;
    }

    /**
     * Get the migrations directory path.
     */
    public function getMigrationPath(): string
    {
        return $this->migrationPath;
    }

    /**
     * Get all migration files, sorted by name (which sorts by timestamp).
     *
     * @return array<int, string>
     */
    public function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationPath)) {
            return [];
        }

        $files = glob($this->migrationPath . '/*.php');

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * Get migration files that have not yet been run.
     *
     * @return array<int, string>
     */
    public function getPendingMigrations(): array
    {
        $ran = $this->repository->getRan();

        return array_values(array_filter(
            $this->getMigrationFiles(),
            static fn (string $file): bool =>
                !in_array(pathinfo($file, PATHINFO_FILENAME), $ran, true)
        ));
    }

    /**
     * Run all pending migrations (optionally limited to a number of files).
     *
     * @return array<int, string> Filenames of the migrations that were run
     */
    public function run(?int $step = null): array
    {
        $this->repository->ensureTable();

        $files = $this->getPendingMigrations();

        if ($step !== null && $step > 0) {
            $files = array_slice($files, 0, $step);
        }

        $ran = [];
        $batch = $this->repository->getNextBatchNumber();

        foreach ($files as $file) {
            $migration = $this->resolve($file);
            $migration->up($this->connection);

            $this->repository->add(pathinfo($file, PATHINFO_FILENAME), $batch);
            $ran[] = basename($file);
        }

        return $ran;
    }

    /**
     * Roll back the last batch of migrations (or several batches).
     *
     * @return array<int, string> Filenames of the migrations rolled back
     */
    public function rollback(int $steps = 1): array
    {
        $this->repository->ensureTable();

        $rolledBack = [];

        while ($steps > 0) {
            $batch = $this->repository->getLastBatchNumber();

            if ($batch === 0) {
                break;
            }

            // Roll back in reverse order — the last migration runs first.
            $migrations = array_reverse(
                $this->repository->getMigrationsByBatch($batch)
            );

            foreach ($migrations as $migration) {
                $file = $this->migrationPath . DIRECTORY_SEPARATOR . $migration . '.php';

                if (!is_file($file)) {
                    throw new MigrationException(
                        'Migration file not found for rollback: ' . $file
                    );
                }

                $instance = $this->resolve($file);
                $instance->down($this->connection);

                $this->repository->delete($migration);
                $rolledBack[] = $migration . '.php';
            }

            $steps--;
        }

        return $rolledBack;
    }

    /**
     * Get the status of every migration.
     *
     * @return array<int, array{migration: string, status: string}>
     */
    public function status(): array
    {
        $this->repository->ensureTable();

        $ran = $this->repository->getRan();
        $rows = [];

        foreach ($this->getMigrationFiles() as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            $rows[] = [
                'migration' => $name,
                'status'    => in_array($name, $ran, true) ? 'Ran' : 'Pending',
            ];
        }

        return $rows;
    }

    /**
     * Create a new migration file from the stub template.
     */
    public function make(string $name): string
    {
        $name = $this->normalizeName($name);

        if ($name === '') {
            throw new MigrationException('Migration name must not be empty.');
        }

        if (!is_dir($this->migrationPath)) {
            mkdir($this->migrationPath, 0777, true);
        }

        $filename = date('Y_m_d_His') . '_' . $name . '.php';
        $path = $this->migrationPath . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($path, $this->stub());

        return $path;
    }

    /**
     * Load a migration file and validate the returned object.
     */
    private function resolve(string $file): Migration
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new MigrationException(
                'Migration file must return a ' . Migration::class
                . ' instance: ' . basename($file)
            );
        }

        return $migration;
    }

    /**
     * Convert a human-friendly name into a snake_case file suffix.
     */
    private function normalizeName(string $name): string
    {
        $name = (string) preg_replace('/[^a-zA-Z0-9_]+/', '_', trim($name));

        return strtolower($name);
    }

    /**
     * The default migration file template.
     */
    private function stub(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Zana\Database\Contracts\ConnectionInterface;
use Zana\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(ConnectionInterface $connection): void
    {
        // Add migration logic here.
    }

    public function down(ConnectionInterface $connection): void
    {
        // Reverse the migration here.
    }
};
PHP;
    }
}

