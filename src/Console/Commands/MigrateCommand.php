<?php

declare(strict_types=1);

namespace Zana\Database\Console\Commands;

use Zana\Console\Commands\Command;
use Zana\Database\Migrations\Migrator;

/**
 * Runs all pending database migrations.
 *
 * Usage:
 *   php bin/console migrate [--step=N]
 */
class MigrateCommand extends Command
{
    protected string $name = 'migrate';

    protected string $description = 'Run the database migrations';

    private Migrator $migrator;

    public function __construct(Migrator $migrator)
    {
        $this->migrator = $migrator;
    }

    public function handle(array $args = []): int
    {
        // Parse optional --step=N argument.
        $step = null;
        foreach ($args as $arg) {
            if (preg_match('/^--step=(\d+)$/', $arg, $m)) {
                $step = (int) $m[1];
            }
        }

        $pending = $this->migrator->getPendingMigrations();

        if ($step !== null && $step > 0) {
            $pending = array_slice($pending, 0, $step);
        }

        if (empty($pending)) {
            $this->info('Nothing to migrate.');
            return 0;
        }

        $this->info(sprintf('Running %d migration(s)...', count($pending)));

        $ran = $this->migrator->run($step);

        foreach ($ran as $file) {
            $this->write('  Migrated: ' . $file);
        }

        $this->write('');

        return 0;
    }
}

