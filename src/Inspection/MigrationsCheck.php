<?php

declare(strict_types=1);

namespace Zana\Database\Inspection;

use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Database\Migration\Migrator;

/**
 * The database is reachable and its migrations are in step with the code
 * (FE-46): a pending migration is a warning, a migration that ran but whose
 * file is gone is a warning, and a database that cannot be read, or a
 * migration file named wrongly, is an error.
 */
final readonly class MigrationsCheck implements ApplicationCheck
{
    /**
     * @param \Closure(): Migrator $migrator Resolved when used: creating the migrator connects to the database
     */
    public function __construct(private \Closure $migrator)
    {
    }

    public function findings(ApplicationInterface $application): array
    {
        try {
            $status = ($this->migrator)()->status();
            $files = ($this->migrator)()->files();
        } catch (\Throwable $e) {
            return [new Finding('database.migrations', Severity::Error, 'The migrations cannot be checked: ' . strtok($e->getMessage(), "\n"), hint: 'Check the database settings (database.connections) and that the server is reachable.')];
        }

        $findings = [];
        foreach ($status as $migration) {
            if (!$migration['ran']) {
                $findings[] = new Finding('database.pending', Severity::Warning, "Migration {$migration['migration']} has not run.", $files[$migration['migration']] ?? null, hint: 'Run: php bin/console migrate');
            } elseif (!isset($files[$migration['migration']])) {
                $findings[] = new Finding('database.missing_file', Severity::Warning, "Migration {$migration['migration']} ran, but its file is gone.", hint: 'Restore the file, or the next rollback cannot undo it.');
            }
        }

        return $findings;
    }
}
