<?php

declare(strict_types=1);

namespace Zana\Database\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\ApplicationInterface;

/**
 * Writes a Doctrine migration class, VersionYYYYMMDDHHMMSS.php (ADR-035): a schema migration, or with --data a data
 * migration. Existing Zana migration files keep running beside it.
 *
 * The class's namespace follows the application's PSR-4 autoload when the directory is under one, and the
 * directory's path otherwise (database/migrations: Database\Migrations). The migrator finds the file by its name,
 * so it needs no autoloading.
 */
#[AsCommand(name: 'make:migration', description: 'Create a migration: a schema change, or with --data a data change')]
final class MakeMigrationCommand extends Command
{
    private const SCHEMA = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace %1$s;

        use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
        use Doctrine\DBAL\Schema\Schema;
        use Doctrine\DBAL\Types\Types;
        use Doctrine\Migrations\AbstractMigration;

        /**
         * A schema migration: it changes tables, columns and indexes, and no data.
         *
         * Change the schema through $schema, Doctrine DBAL's schema objects, which give the same result on every
         * database, or with $this->addSql() for what they cannot express.
         *
         * It runs in a transaction where a schema change can be undone (SQLite, PostgreSQL), and without one on MySQL
         * and MariaDB, where a schema change commits the transaction anyway. Data changed here could therefore not be
         * undone on those databases: change data in a migration of its own, `make:migration <name> --data`.
         *
         * Once it has run, never rename this class or change its namespace: it is recorded by that name, and would run
         * again as a new migration.
         */
        final class %2$s extends AbstractMigration
        {
            public function getDescription(): string
            {
                return '%3$s';
            }

            public function isTransactional(): bool
            {
                return !$this->platform instanceof AbstractMySQLPlatform;
            }

            public function up(Schema $schema): void
            {
        %4$s
            }

            public function down(Schema $schema): void
            {
        %5$s
            }
        }

        PHP;

    private const DATA = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace %1$s;

        use Doctrine\DBAL\Schema\Schema;
        use Doctrine\Migrations\AbstractMigration;

        /**
         * A data migration: it changes rows, and no schema.
         *
         * It runs in a transaction on every database, with its line in zana_migrations, so it changes everything or
         * nothing. It has no down(), so it cannot be rolled back: migrate:rollback refuses a batch that holds it before
         * anything runs, and migrate:status shows it as irreversible.
         *
         * - Change no schema here: on MySQL and MariaDB a schema change commits the transaction.
         * - Match the old value, so a database already changed by hand is left alone and running it again changes
         *   nothing.
         * - Pass values as bindings, never in the SQL.
         * - Read with $this->connection, which runs at once; write with $this->addSql(), which is queued until up()
         *   returns, so after every read.
         * - Use tables and values copied in here, never repositories, entities or application services: they follow
         *   today's code, not the schema of this migration's time.
         * - Content kept with revisions (audit_revisions) gets its revisions here too, as an editor's change would.
         * - A correction of content a seed writes goes in the seed too, so a new database has it.
         *
         * Once it has run, never rename this class or change its namespace: it is recorded by that name, and would run
         * again as a new migration.
         */
        final class %2$s extends AbstractMigration
        {
            public function getDescription(): string
            {
                return '%3$s';
            }

            public function up(Schema $schema): void
            {
                $old = 'The text before.';
                $new = 'The text after.';

                $rows = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM table_name WHERE column_name = ?', [$old]);
                $this->warnIf($rows === 0, 'No row has the text before: nothing to change.');

                $this->addSql('UPDATE table_name SET column_name = ? WHERE column_name = ?', [$new, $old]);
            }
        }

        PHP;

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'What it does, e.g. create_patients_table or add_launch_dates')
            ->addOption('data', null, InputOption::VALUE_NONE, 'A data migration: transactional on every database, and irreversible')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory relative to the base path', 'database/migrations');
    }

    public function handle(InputInterface $input, SymfonyStyle $io, ApplicationInterface $app): int
    {
        $name = strtolower(trim((string) $input->getArgument('name')));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $io->error('The name may only contain lower-case letters, digits and underscores, starting with a letter.');

            return self::INVALID;
        }

        $relative = trim(str_replace('\\', '/', (string) $input->getOption('path')), '/');
        if ($relative === '' || preg_match('#(^|/)\.\.?(/|$)|:|\x00#', $relative) === 1) {
            $io->error('--path must be a directory inside the project, relative to its base path, e.g. database/migrations.');

            return self::INVALID;
        }
        $directory = $app->path($relative);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $io->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $class = 'Version' . date('YmdHis');
        $file = "{$directory}/{$class}.php";
        if (file_exists($file)) {
            $io->error("{$file} exists: wait a second and run the command again.");

            return self::FAILURE;
        }

        $namespace = self::namespaceFor($app->basePath(), $relative);
        if ($input->getOption('data') === true) {
            $code = sprintf(self::DATA, $namespace, $class, $name);
        } else {
            [$up, $down] = self::schemaBody($name);
            $code = sprintf(self::SCHEMA, $namespace, $class, $name, $up, $down);
        }
        file_put_contents($file, $code);

        $io->success("Created {$file}");

        return self::SUCCESS;
    }

    /**
     * @return array{string, string} The bodies of up() and down()
     */
    private static function schemaBody(string $name): array
    {
        $indent = static fn (string ...$lines): string => implode("\n", array_map(static fn (string $line): string => "        {$line}", $lines));
        if (preg_match('/^create_(\w+?)(_table)?$/', $name, $match) === 1) {
            return [
                $indent(
                    "\$table = \$schema->createTable('{$match[1]}');",
                    "\$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true]);",
                    "\$table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => false]);",
                    "\$table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false]);",
                    "\$table->setPrimaryKey(['id']);",
                ),
                $indent("\$schema->dropTable('{$match[1]}');"),
            ];
        }

        return [
            $indent(
                "// \$table = \$schema->getTable('table_name');",
                "// \$table->addColumn('notes', Types::TEXT, ['notnull' => false]);",
            ),
            $indent(
                "// \$schema->getTable('table_name')->dropColumn('notes');",
            ),
        ];
    }

    /**
     * The namespace of a class in this directory: from the application's PSR-4 autoload when the directory is under
     * one of its roots, and from the directory's path otherwise.
     */
    private static function namespaceFor(string $basePath, string $relative): string
    {
        $composer = json_decode((string) @file_get_contents($basePath . '/composer.json'), true);
        $roots = is_array($composer) && is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : [];
        $best = null;
        foreach ($roots as $prefix => $dirs) {
            // Written into the generated file: only a real namespace.
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*\\\\)*$/', (string) $prefix) !== 1) {
                continue;
            }
            foreach ((array) $dirs as $dir) {
                $dir = trim(str_replace('\\', '/', (string) $dir), '/');
                if ($dir === '' || $relative === $dir || str_starts_with($relative, $dir . '/')) {
                    if ($best === null || strlen($dir) > strlen($best[1])) {
                        $best = [rtrim((string) $prefix, '\\'), $dir];
                    }
                }
            }
        }
        $segments = static fn (string $path): array => array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        if ($best !== null) {
            $rest = array_map(self::studly(...), $segments(substr($relative, strlen($best[1]))));

            return implode('\\', array_filter([$best[0], ...$rest], static fn (string $s): bool => $s !== ''));
        }

        return implode('\\', array_map(self::studly(...), $segments($relative))) ?: 'Migrations';
    }

    private static function studly(string $segment): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $segment)));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $studly) !== 1) {
            $studly = 'N' . preg_replace('/\W/', '', $studly);
        }

        return $studly;
    }
}
