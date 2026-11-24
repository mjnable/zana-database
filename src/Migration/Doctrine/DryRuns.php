<?php

declare(strict_types=1);

namespace Zana\Database\Migration\Doctrine;

use Doctrine\Migrations\Event\MigrationsVersionEventArgs;
use Zana\Database\Migration\MigrationException;

/**
 * @internal Doctrine's dry run holds back only the statements queued with addSql(). A Zana migration's schema builder
 * runs each statement at once, so a dry run of one would change the database: it is refused before it starts.
 *
 * Zana exposes no dry run (ADR-035); this stands for the day a Doctrine command does.
 */
final class DryRuns
{
    public function onMigrationsVersionExecuting(MigrationsVersionEventArgs $args): void
    {
        if ($args->getMigratorConfiguration()->isDryRun() && $args->getPlan()->getMigration() instanceof FileMigration) {
            throw new MigrationException("Migration {$args->getPlan()->getVersion()} is a Zana migration file: its schema builder runs each statement at once, so it cannot be dry-run.");
        }
    }
}
