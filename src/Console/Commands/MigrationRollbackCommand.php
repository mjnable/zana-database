<?php

declare(strict_types=1);

namespace Zana\Database\Console\Commands;

use Zana\Console\Commands\Command;
use Zana\Database\Migrations\Migrator;

/**
 * Rolls back the last batch of database migrations.
 *
 * Usage:
 *   php bin/console migrate:rollback [steps]
 */
class MigrationRollbackCommand extends Command
{
    protected string $name = 'migrate:rollback';

    protected string $description = 'Roll back the last batch of migrations';

    private Migrator $migrator;

    public function __construct(Migrator $migrator)
    {
        $this->migrator = $migrator;
    }

    public function handle(array $args = []): int
    {
        $steps = 1;

        if (isset($args[0]) && is_numeric($args[0])) {
            $steps = max(1, (int) $args[0]);
        }

        $this->info('Rolling back migrations...');

        $rolledBack = $this->migrator->rollback($steps);

        if (empty($rolledBack)) {
            $this->write('  Nothing to roll back.');
        }

        foreach ($rolledBack as $file) {
            $this->write('  Rolled back: ' . $file);
        }

        $this->write('');

        return 0;
    }
}

