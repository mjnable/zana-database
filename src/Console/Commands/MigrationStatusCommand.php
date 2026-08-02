<?php

declare(strict_types=1);

namespace Zana\Database\Console\Commands;

use Zana\Console\Commands\Command;
use Zana\Database\Migrations\Migrator;

/**
 * Shows the status of every migration.
 *
 * Usage:
 *   php bin/console migrate:status
 */
class MigrationStatusCommand extends Command
{
    protected string $name = 'migrate:status';

    protected string $description = 'Show the status of each migration';

    private Migrator $migrator;

    public function __construct(Migrator $migrator)
    {
        $this->migrator = $migrator;
    }

    public function handle(array $args = []): int
    {
        $rows = $this->migrator->status();

        if (empty($rows)) {
            $this->info('No migrations found in ' . $this->migrator->getMigrationPath());
            return 0;
        }

        $this->write(str_pad('Migration', 60) . 'Status');
        $this->write(str_repeat('-', 70));

        foreach ($rows as $row) {
            $this->write(str_pad($row['migration'], 60) . $row['status']);
        }

        $this->write('');

        return 0;
    }
}

