<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseServiceProvider;
use Zana\Database\Migration\MigrationException;
use Zana\Database\Migration\MigrationRepository;
use Zana\Database\Migration\Migrator;

/**
 * FE-83, ADR-035: the migrator on Doctrine Migrations. Existing Zana files and their history carry over, Doctrine
 * classes run beside them, and the three safeguards hold on every database the suite reaches.
 */
final class DoctrineMigrationsTest extends TestCase
{
    private const array TABLES = ['mig_a', 'mig_b', 'mig_c', 'mig_notes', 'mig_extra', 'zana_migrations'];

    /** A namespace of this test's own, so the Doctrine classes of two tests never collide. */
    private string $namespace = '';

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        $this->namespace = 'Zana\\Database\\Tests\\Generated\\T' . bin2hex(random_bytes(5));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return Drivers::names();
    }

    // ── history ────────────────────────────────────────────────────────────

    #[DataProvider('drivers')]
    public function testTheHistoryOfTheZanaEngineCarriesOverUnchanged(string $driver): void
    {
        $settings = $this->settings($driver, $driver === 'sqlite' ? $this->directory() . '/app.sqlite' : null);
        $this->clean(ConnectionFactory::create($settings));
        $dir = $this->directory();
        $this->zanaTable($dir, '2026_01_01_000000_create_a', 'mig_a');
        $this->zanaTable($dir, '2026_01_02_000000_create_b', 'mig_b');

        // 2.36's engine, which still runs a connection that is not a DbalConnection.
        $legacy = ConnectionFactory::createPdo($settings);
        $this->assertSame(['2026_01_01_000000_create_a', '2026_01_02_000000_create_b'], (new Migrator($legacy, new MigrationRepository($legacy), [$dir]))->migrate());
        unset($legacy);

        $db = ConnectionFactory::create($settings);
        $migrator = $this->migrator($db, [$dir]);
        $ran = [
            ['migration' => '2026_01_01_000000_create_a', 'ran' => true, 'batch' => 1],
            ['migration' => '2026_01_02_000000_create_b', 'ran' => true, 'batch' => 1],
        ];
        $this->assertSame($ran, $migrator->status());
        $this->assertSame([], $migrator->migrate(), 'nothing is pending');
        $this->assertSame(['2026_01_02_000000_create_b', '2026_01_01_000000_create_a'], $migrator->rollback(), 'their down() runs on Doctrine');
        $this->assertFalse($db->schema()->hasTable('mig_a'));
        $this->assertSame(['2026_01_01_000000_create_a', '2026_01_02_000000_create_b'], $migrator->migrate());
        $this->assertSame($ran, $migrator->status());
    }

    public function testZanaFilesAndDoctrineClassesRunInTheOrderOfTheirDates(): void
    {
        $db = $this->connect('sqlite');
        $app = $this->directory();
        $module = $this->directory();
        $this->zanaTable($app, '2026_01_01_000000_create_a', 'mig_a');
        $this->doctrineClass($module, 'Version20260102000000', "\$this->addSql('CREATE TABLE mig_b (id INT)');", down: "\$this->addSql('DROP TABLE mig_b');");
        $this->zanaTable($module, '2026_01_03_000000_create_c', 'mig_c');
        $migrator = $this->migrator($db, [$app, $module]);

        $expected = ['2026_01_01_000000_create_a', "{$this->namespace}\\Version20260102000000", '2026_01_03_000000_create_c'];
        $this->assertSame($expected, $migrator->migrate());
        $this->assertSame($expected, array_column($migrator->status(), 'migration'));
        $this->assertSame([1, 1, 1], array_column($migrator->status(), 'batch'));
        $this->assertSame([], $migrator->irreversible());
        $this->assertSame(array_reverse($expected), $migrator->reset());
        $this->assertFalse($db->schema()->hasTable('mig_b'));
    }

    // ── data migrations ────────────────────────────────────────────────────

    #[DataProvider('drivers')]
    public function testADataMigrationChangesRowsWithBindingsAndSaysWhatItFound(string $driver): void
    {
        [$db, $dir, $migrator] = $this->notes($driver);
        $this->doctrineClass($dir, 'Version20260201000000', <<<'PHP'
            $found = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mig_notes WHERE body = ?', ['old']);
            $this->write("Found {$found}.");
            $this->warnIf($found < 3, 'Fewer than three.');
            $this->addSql('UPDATE mig_notes SET body = ? WHERE body = ?', ['new', 'old']);
            PHP);
        $name = "{$this->namespace}\\Version20260201000000";

        $this->assertSame([$name], $migrator->migrate());

        $this->assertSame(['kept', 'new', 'new'], $this->bodies($db));
        $this->assertSame([['level' => 'notice', 'message' => 'Found 2.'], ['level' => 'warning', 'message' => 'Fewer than three.']], $migrator->messages());
        $this->assertSame([], $migrator->messages(), 'each message is returned once');
        $this->assertSame([$name], $migrator->irreversible());
        $this->assertSame(2, (new MigrationRepository($db))->ran()[$name]);
    }

    #[DataProvider('drivers')]
    public function testADataMigrationThatFailsHalfWayChangesNothing(string $driver): void
    {
        [$db, $dir, $migrator] = $this->notes($driver);
        $this->doctrineClass($dir, 'Version20260201000000', <<<'PHP'
            $this->addSql('UPDATE mig_notes SET body = ? WHERE body = ?', ['new', 'old']);
            $this->addSql('UPDATE mig_missing SET x = 1');
            PHP);

        $this->assertMigrationFails($migrator, "Migration {$this->namespace}\\Version20260201000000 failed (up): ");

        $this->assertSame(['kept', 'old', 'old'], $this->bodies($db));
        $this->assertArrayNotHasKey("{$this->namespace}\\Version20260201000000", (new MigrationRepository($db))->ran());
    }

    /**
     * The safeguard for MySQL and MariaDB: a schema change commits the transaction, and DBAL still counted it as
     * open, so the next migration of the run lost its atomicity (the PoC: 4 rows stayed changed, silently).
     */
    #[DataProvider('drivers')]
    public function testAfterASchemaChangeTheNextDataMigrationIsStillAtomic(string $driver): void
    {
        [$db, $dir, $migrator] = $this->notes($driver);
        $this->doctrineClass($dir, 'Version20260201000000', "\$this->addSql('CREATE TABLE mig_extra (id INT)');", down: "\$this->addSql('DROP TABLE mig_extra');");
        $this->doctrineClass($dir, 'Version20260202000000', <<<'PHP'
            $this->addSql('UPDATE mig_notes SET body = ? WHERE body = ?', ['new', 'old']);
            $this->addSql('UPDATE mig_missing SET x = 1');
            PHP);

        $this->assertMigrationFails($migrator, "Migration {$this->namespace}\\Version20260202000000 failed (up): ");

        $this->assertSame(['kept', 'old', 'old'], $this->bodies($db), 'the failed data migration changed nothing');
        $ran = (new MigrationRepository($db))->ran();
        $this->assertArrayNotHasKey("{$this->namespace}\\Version20260202000000", $ran);
        $warnings = array_column(array_filter($migrator->messages(), static fn (array $m): bool => $m['level'] === 'warning'), 'message');
        if ($driver === 'mysql') {
            $this->assertArrayHasKey("{$this->namespace}\\Version20260201000000", $ran, 'the schema change itself stays: it was committed');
            $this->assertStringContainsString("Migration {$this->namespace}\\Version20260201000000 changed the schema inside its transaction", implode("\n", $warnings));
        } else {
            $this->assertArrayNotHasKey("{$this->namespace}\\Version20260201000000", $ran, 'the whole batch was rolled back');
            $this->assertFalse($db->schema()->hasTable('mig_extra'));
            $this->assertSame([], $warnings);
        }
    }

    #[DataProvider('drivers')]
    public function testAMigrationThatMixesDataAndSchemaSaysWhatStays(string $driver): void
    {
        [$db, $dir, $migrator] = $this->notes($driver);
        $this->doctrineClass($dir, 'Version20260201000000', <<<'PHP'
            $this->addSql('UPDATE mig_notes SET body = ? WHERE body = ?', ['new', 'old']);
            $this->addSql('CREATE TABLE mig_extra (id INT)');
            $this->addSql('UPDATE mig_missing SET x = 1');
            PHP);

        $message = $this->assertMigrationFails($migrator, "Migration {$this->namespace}\\Version20260201000000 failed (up): ");

        if ($driver === 'mysql') {
            $this->assertStringEndsWith('Its statements before the schema change stay applied: on this database a schema change commits the transaction.', $message);
            $this->assertSame(['kept', 'new', 'new'], $this->bodies($db));
        } else {
            $this->assertStringNotContainsString('stay applied', $message);
            $this->assertSame(['kept', 'old', 'old'], $this->bodies($db));
        }
    }

    public function testASkippedMigrationStaysPendingAndSaysWhy(): void
    {
        $db = $this->connect('sqlite');
        $dir = $this->directory();
        $this->doctrineClass($dir, 'Version20260201000000', "\$this->skipIf(true, 'Not needed here.');");
        $migrator = $this->migrator($db, [$dir]);

        $this->assertSame([], $migrator->migrate());

        $this->assertStringContainsString('Not needed here.', implode("\n", array_column($migrator->messages(), 'message')));
        $this->assertFalse($migrator->status()[0]['ran']);
    }

    // ── rollback ───────────────────────────────────────────────────────────

    #[DataProvider('drivers')]
    public function testARollbackOverAnIrreversibleMigrationIsRefusedBeforeAnyDown(string $driver): void
    {
        $db = $this->connect($driver);
        $dir = $this->directory();
        $this->zanaTable($dir, '2026_03_01_000000_create_a', 'mig_a');
        $this->doctrineClass($dir, 'Version20260302000000', "\$this->addSql('UPDATE mig_a SET id = id WHERE 1 = 0');");
        $this->zanaTable($dir, '2026_03_03_000000_create_c', 'mig_c');
        $migrator = $this->migrator($db, [$dir]);
        $migrator->migrate();

        try {
            $migrator->rollback();
            $this->fail('The rollback must be refused.');
        } catch (MigrationException $e) {
            $this->assertSame("Cannot roll back {$this->namespace}\\Version20260302000000: it has no down(), so it cannot be undone. Nothing was rolled back.", $e->getMessage());
        }

        $this->assertTrue($db->schema()->hasTable('mig_c'), 'the newest down() did not run');
        $this->assertTrue($db->schema()->hasTable('mig_a'));
        $this->assertSame([true, true, true], array_column($migrator->status(), 'ran'));
        $this->assertSame([1, 1, 1], array_column($migrator->status(), 'batch'));
    }

    public function testARollbackOfAMigrationWhoseFileIsGoneIsRefused(): void
    {
        $db = $this->connect('sqlite');
        $dir = $this->directory();
        $this->zanaTable($dir, '2026_03_01_000000_create_a', 'mig_a');
        $migrator = $this->migrator($db, [$dir]);
        $migrator->migrate();
        unlink("{$dir}/2026_03_01_000000_create_a.php");

        $this->expectExceptionMessage('Cannot roll back 2026_03_01_000000_create_a: its file is missing.');
        $migrator->rollback();
    }

    // ── dry runs ───────────────────────────────────────────────────────────

    public function testADryRunOfAZanaFileIsRefusedAndADoctrineClassStillDryRuns(): void
    {
        $db = $this->connect('sqlite');
        $dir = $this->directory();
        $this->zanaTable($dir, '2026_04_01_000000_create_a', 'mig_a');
        $this->doctrineClass($dir, 'Version20260402000000', "\$this->addSql('CREATE TABLE mig_b (id INT)');", down: "\$this->addSql('DROP TABLE mig_b');");
        $migrator = $this->migrator($db, [$dir]);
        $migrator->files();
        $doctrine = $migrator->doctrine();
        $doctrine->getMetadataStorage()->ensureInitialized();
        $dryRun = static fn (string $name) => $doctrine->getMigrator()->migrate(
            $doctrine->getMigrationPlanCalculator()->getPlanForVersions([new Version($name)], Direction::UP),
            (new MigratorConfiguration())->setDryRun(true),
        );

        try {
            $dryRun('2026_04_01_000000_create_a');
            $this->fail('A dry run of a Zana file must be refused.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('2026_04_01_000000_create_a is a Zana migration file', $e->getMessage());
        }
        $this->assertFalse($db->schema()->hasTable('mig_a'), 'nothing ran');

        $dryRun("{$this->namespace}\\Version20260402000000");
        $this->assertFalse($db->schema()->hasTable('mig_b'));
        $this->assertSame([false, false], array_column($migrator->status(), 'ran'));
    }

    // ── files ──────────────────────────────────────────────────────────────

    public function testInvalidDoctrineMigrationFilesAreRejected(): void
    {
        $badName = $this->directory();
        file_put_contents("{$badName}/Version1.php", '<?php');
        $otherClass = $this->directory();
        file_put_contents("{$otherClass}/Version20260101000000.php", "<?php\nnamespace {$this->namespace}\\Other;\nfinal class Something {}\n");
        $notAMigration = $this->directory();
        file_put_contents("{$notAMigration}/Version20260101000000.php", "<?php\nnamespace {$this->namespace}\\Plain;\nfinal class Version20260101000000 {}\n");
        $db = $this->connect('sqlite');

        $messages = [];
        foreach ([$badName, $otherClass, $notAMigration] as $dir) {
            try {
                $this->migrator($db, [$dir])->migrate();
                $this->fail('An invalid migration must be rejected.');
            } catch (MigrationException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertStringContainsString('or VersionYYYYMMDDHHMMSS.php for a Doctrine migration class', $messages[0]);
        $this->assertStringContainsString("must declare the class {$this->namespace}\\Other\\Version20260101000000, extending " . AbstractMigration::class, $messages[1]);
        $this->assertStringContainsString("must declare the class {$this->namespace}\\Plain\\Version20260101000000", $messages[2]);
    }

    public function testTheSameDoctrineClassInTwoFilesIsRejected(): void
    {
        $first = $this->directory();
        $second = $this->directory();
        $this->doctrineClass($first, 'Version20260101000000', "\$this->addSql('SELECT 1');");
        $this->doctrineClass($second, 'Version20260101000000', "\$this->addSql('SELECT 2');");
        $db = $this->connect('sqlite');

        $this->expectExceptionMessage("Migration {$this->namespace}\\Version20260101000000 exists in two files");
        $this->migrator($db, [$first, $second])->files();
    }

    public function testAComposerPrefixThatIsNotANamespaceIsNotWrittenIntoTheMigration(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
        file_put_contents("{$base}/composer.json", json_encode(['autoload' => ['psr-4' => ["App;system('calc');//\\" => 'src/']]]));
        $kernel = Application::configure($base)
            ->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class)
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);

        $this->console($kernel, ['command' => 'make:migration', 'name' => 'fix_notes', '--data' => true, '--path' => 'src/Data']);

        $code = (string) file_get_contents((glob("{$base}/src/Data/Version*.php") ?: [])[0] ?? '');
        $this->assertStringContainsString("namespace Src\\Data;", $code);
        $this->assertStringNotContainsString('system(', $code);
    }

    public function testMakeMigrationWritesOnlyInsideTheProject(): void
    {
        $root = $this->directory();
        $base = "{$root}/project";
        mkdir("{$base}/config", 0777, true);
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
        $kernel = Application::configure($base)
            ->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class)
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);

        foreach (['../outside', 'database/../../outside', '..', '.', './x', 'C:/outside', '', "a\0b"] as $path) {
            $this->assertStringContainsString('--path must be a directory inside the project', $this->console($kernel, ['command' => 'make:migration', 'name' => 'x', '--path' => $path]), json_encode($path) ?: '');
        }

        $this->assertSame(['project'], array_map('basename', glob("{$root}/*") ?: []), 'nothing was written outside the project');
        $this->assertSame(['config'], array_map('basename', glob("{$base}/*") ?: []));
    }

    public function testWhatAMigrationSaysIsShownAsPlainText(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
        mkdir("{$base}/database/migrations", 0777, true);
        $this->doctrineClass("{$base}/database/migrations", 'Version20260101000000', "\$this->write('<error>not a tag</error> and a < b');\n\$this->addSql('SELECT 1');");
        $kernel = Application::configure($base)
            ->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class)
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);

        $this->assertStringContainsString('<error>not a tag</error> and a < b', $this->console($kernel, ['command' => 'migrate']));
    }

    public function testTheDeprecatedPdoConnectionRunsZanaFilesOnly(): void
    {
        $legacy = ConnectionFactory::createPdo(['driver' => 'sqlite', 'database' => ':memory:']);
        $dir = $this->directory();
        $this->zanaTable($dir, '2026_01_01_000000_create_a', 'mig_a');
        $migrator = new Migrator($legacy, new MigrationRepository($legacy), [$dir]);
        $this->assertSame(['2026_01_01_000000_create_a'], $migrator->migrate());

        $this->doctrineClass($dir, 'Version20260102000000', "\$this->addSql('SELECT 1');");
        $this->expectExceptionMessage('is a Doctrine migration class: it runs on the DBAL connection that ConnectionFactory::create() makes');
        $migrator->migrate();
    }

    // ── logging ────────────────────────────────────────────────────────────

    public function testTheLogGetsWhatMigrationsSayAndErrorsButNotDoctrinesProgress(): void
    {
        $db = $this->connect('sqlite');
        $dir = $this->directory();
        $this->zanaTable($dir, '2026_01_01_000000_create_a', 'mig_a');
        $this->doctrineClass($dir, 'Version20260102000000', "\$this->write('Updated the ids.');\n\$this->addSql('UPDATE mig_a SET id = ? WHERE id = ?', [7, 424242]);");
        $this->doctrineClass($dir, 'Version20260103000000', "\$this->addSql('UPDATE mig_missing SET x = 1');");
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
            }
        };

        try {
            (new Migrator($db, new MigrationRepository($db), [$dir], $logger))->migrate();
            $this->fail('The last migration must fail.');
        } catch (MigrationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertCount(2, $logger->lines, implode("\n", $logger->lines));
        $this->assertStringStartsWith('notice Updated the ids.', $logger->lines[0]);
        $this->assertStringStartsWith('error Migration {version} failed during {state}.', $logger->lines[1]);
        $this->assertStringContainsString('Version20260103000000', $logger->lines[1]);
        $this->assertStringNotContainsString('424242', implode("\n", $logger->lines));
    }

    // ── commands ───────────────────────────────────────────────────────────

    public function testTheCommandsMakeAndRunBothKindsOfMigration(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
        // database/ gets a namespace of this test's own: another test makes Database\Migrations\Version<now> in this process.
        file_put_contents("{$base}/composer.json", json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/', "{$this->namespace}\\" => 'database/']]]));
        mkdir("{$base}/database/migrations", 0777, true);
        $this->notesTable("{$base}/database/migrations");
        $kernel = Application::configure($base)
            ->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s)
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);

        // A data migration, written where the migrator does not look, filled in, then moved in.
        $this->assertStringContainsString('Created', $this->console($kernel, ['command' => 'make:migration', 'name' => 'fix_notes', '--data' => true, '--path' => 'src/Data']));
        $data = (glob("{$base}/src/Data/Version*.php") ?: [])[0] ?? '';
        $code = (string) file_get_contents($data);
        $this->assertStringContainsString("namespace App\\Data;", $code, 'the namespace follows PSR-4');
        $this->assertStringContainsString("return 'fix_notes';", $code);
        $this->assertStringNotContainsString('function down', $code);
        $this->assertStringNotContainsString('function isTransactional', $code, 'transactional on every database');
        // Filled in, and dated before the schema migration below, which may be made in the same second.
        $class = pathinfo($data, PATHINFO_FILENAME);
        $code = str_replace(['table_name', 'column_name', 'The text before.', 'The text after.', $class], ['mig_notes', 'body', 'old', 'new', 'Version20260102000000'], $code);
        file_put_contents("{$base}/database/migrations/Version20260102000000.php", $code);
        unlink($data);

        $this->assertStringContainsString('Created', $this->console($kernel, ['command' => 'make:migration', 'name' => 'create_clinics_table']));
        $created = array_values(array_diff(glob("{$base}/database/migrations/Version*.php") ?: [], ["{$base}/database/migrations/Version20260102000000.php"]));
        $this->assertCount(1, $created);
        $code = (string) file_get_contents($created[0]);
        $this->assertStringContainsString("namespace {$this->namespace}\\Migrations;", $code);
        $this->assertStringContainsString("createTable('clinics')", $code);

        $this->assertStringContainsString('Ran 3 migrations', $this->console($kernel, ['command' => 'migrate']));
        $status = $this->console($kernel, ['command' => 'migrate:status']);
        $this->assertStringContainsString('Irreversible (no down())', $status);
        $this->assertSame(1, substr_count($status, 'Irreversible'));
        $refused = $this->console($kernel, ['command' => 'migrate:rollback']);
        $this->assertStringContainsString('Nothing was rolled back', $refused);
    }

    public function testTheMigrateCommandShowsWhatMigrationsSayEvenWhenOneFails(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
        mkdir("{$base}/database/migrations", 0777, true);
        $this->doctrineClass("{$base}/database/migrations", 'Version20260101000000', "\$this->write('Checked the rows.');\n\$this->addSql('UPDATE mig_missing SET x = 1');");
        $kernel = Application::configure($base)
            ->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class)
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);

        $output = new BufferedOutput();
        $status = $kernel->run(new ArrayInput(['command' => 'migrate']), $output);

        // The error itself is wrapped at the terminal's width, even inside a word: only its exit code is asserted.
        $this->assertNotSame(0, $status, 'the command failed');
        $this->assertStringContainsString('Checked the rows.', $output->fetch());
    }

    public function testAGeneratedSchemaMigrationIsTransactionalExceptOnMysqlAndMariadb(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
        $kernel = Application::configure($base)
            ->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class)
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);
        $this->console($kernel, ['command' => 'make:migration', 'name' => 'add_notes', '--path' => 'generated/' . substr($this->namespace, -11)]);
        $file = (glob("{$base}/generated/*/Version*.php") ?: [])[0] ?? '';
        require_once $file;
        $class = 'Generated\\' . substr($this->namespace, -11) . '\\' . pathinfo($file, PATHINFO_FILENAME);

        $on = static function (array $params) use ($class): bool {
            $migration = new $class(DriverManager::getConnection($params), new NullLogger());
            assert($migration instanceof AbstractMigration);

            return $migration->isTransactional();
        };

        $this->assertFalse($on(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']));
        $this->assertFalse($on(['driver' => 'pdo_mysql', 'serverVersion' => '11.4.0-MariaDB']));
        $this->assertTrue($on(['driver' => 'pdo_pgsql', 'serverVersion' => '17.0']));
        $this->assertTrue($on(['driver' => 'pdo_sqlite', 'memory' => true]));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    #[After]
    public function removeDirectories(): void
    {
        foreach ($this->directories as $directory) {
            $this->removeTree($directory);
        }
    }

    /**
     * A database with mig_notes (old, old, kept) made by a Zana file in batch 1, the directory and its migrator.
     *
     * @return array{ConnectionInterface, string, Migrator}
     */
    private function notes(string $driver): array
    {
        $db = $this->connect($driver);
        $dir = $this->directory();
        $this->notesTable($dir);
        $migrator = $this->migrator($db, [$dir]);
        $migrator->migrate();
        foreach (['old', 'old', 'kept'] as $body) {
            $db->table('mig_notes')->insert(['body' => $body]);
        }

        return [$db, $dir, $migrator];
    }

    /**
     * @return list<string>
     */
    private function bodies(ConnectionInterface $db): array
    {
        $bodies = array_map(static fn (array $row): string => (string) $row['body'], $db->table('mig_notes')->get());
        sort($bodies);

        return $bodies;
    }

    /**
     * @param non-empty-string $prefix
     */
    private function assertMigrationFails(Migrator $migrator, string $prefix): string
    {
        try {
            $migrator->migrate();
        } catch (MigrationException $e) {
            $this->assertStringStartsWith($prefix, $e->getMessage());

            return $e->getMessage();
        }
        $this->fail('The migration must fail.');
    }

    /**
     * @param list<string> $paths
     */
    private function migrator(ConnectionInterface $db, array $paths): Migrator
    {
        return new Migrator($db, new MigrationRepository($db), $paths);
    }

    private function connect(string $driver): ConnectionInterface
    {
        $db = ConnectionFactory::create($this->settings($driver));
        $this->clean($db);

        return $db;
    }

    private function clean(ConnectionInterface $db): void
    {
        foreach (self::TABLES as $table) {
            $db->schema()->dropIfExists($table);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(string $driver, ?string $sqliteFile = null): array
    {
        $settings = Drivers::settings($driver, $sqliteFile);
        if (is_string($settings)) {
            $this->markTestSkipped($settings);
        }

        return $settings;
    }

    private function notesTable(string $directory): void
    {
        file_put_contents("{$directory}/2026_01_01_000000_create_notes.php", <<<'PHP'
            <?php
            use Zana\Database\Migration\Migration;
            use Zana\Database\Schema\Blueprint;
            use Zana\Database\Schema\Schema;
            return new class () extends Migration {
                public function up(Schema $schema): void { $schema->create('mig_notes', static function (Blueprint $t): void { $t->id(); $t->string('body'); }); }
                public function down(Schema $schema): void { $schema->drop('mig_notes'); }
            };
            PHP);
    }

    private function zanaTable(string $directory, string $name, string $table): void
    {
        file_put_contents("{$directory}/{$name}.php", <<<PHP
            <?php
            use Zana\\Database\\Migration\\Migration;
            use Zana\\Database\\Schema\\Blueprint;
            use Zana\\Database\\Schema\\Schema;
            return new class () extends Migration {
                public function up(Schema \$schema): void { \$schema->create('{$table}', static function (Blueprint \$t): void { \$t->id(); }); }
                public function down(Schema \$schema): void { \$schema->drop('{$table}'); }
            };
            PHP);
    }

    /**
     * A Doctrine migration class in this test's namespace; with no $down, it has no down() and is irreversible.
     */
    private function doctrineClass(string $directory, string $class, string $up, ?string $down = null): void
    {
        $method = static fn (string $name, string $body): string => "    public function {$name}(Schema \$schema): void\n    {\n        " . str_replace("\n", "\n        ", $body) . "\n    }\n";
        file_put_contents("{$directory}/{$class}.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$this->namespace};\n\nuse Doctrine\\DBAL\\Schema\\Schema;\nuse Doctrine\\Migrations\\AbstractMigration;\n\nfinal class {$class} extends AbstractMigration\n{\n"
            . $method('up', $up) . ($down === null ? '' : $method('down', $down)) . "}\n");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(ConsoleKernel $kernel, array $input): string
    {
        $output = new BufferedOutput();
        $kernel->run(new ArrayInput($input), $output);

        return $output->fetch();
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/zana-dm-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->directories[] = $directory;

        return $directory;
    }

    private function removeTree(string $directory): void
    {
        foreach (glob("{$directory}/*") ?: [] as $file) {
            is_dir($file) ? $this->removeTree($file) : unlink($file);
        }
        @rmdir($directory);
    }
}
