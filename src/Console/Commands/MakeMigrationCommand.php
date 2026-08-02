<?php

declare(strict_types=1);

namespace Zana\Database\Console\Commands;

use Zana\Console\Commands\Command;
use Zana\Database\Migrations\Migrator;

/**
 * Creates a new migration file.
 *
 * Usage:
 *   php bin/console migrate:make create_users_table
 */
class MakeMigrationCommand extends Command
{
    protected string $name = 'migrate:make';

    protected string $description = 'Create a new migration file';

    private Migrator $migrator;

    public function __construct(Migrator $migrator)
    {
        $this->migrator = $migrator;
    }

    public function handle(array $args = []): int
    {
        $name = $args[0] ?? '';

        if ($name === '') {
            $this->error('Migration name is required.');
            $this->write('Usage: php bin/console migrate:make <name>');
            return 1;
        }

        $path = $this->migrator->make($name);

        $this->write('Created migration: ' . $path);

        return 0;
    }
}

