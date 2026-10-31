<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\Inspection\Severity;
use Zana\Database\Connection;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseException;
use Zana\Database\DatabaseServiceProvider;
use Zana\Database\Grammar\MysqlGrammar;
use Zana\Database\Grammar\PostgresGrammar;
use Zana\Database\Inspection\MigrationsCheck;
use Zana\Database\Inspection\MigrationsSection;
use Zana\Database\Migration\MigrationException;
use Zana\Database\Migration\MigrationRepository;
use Zana\Database\Migration\Migrator;
use Zana\Database\Schema\Blueprint;
use Zana\Database\TransactionalUnitOfWork;
use Zana\Ddd\Domain\UnitOfWorkInterface;

final class SchemaAndMigrationTest extends TestCase
{
    private Connection $db;

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    #[After]
    public function removeDirectories(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob("{$directory}/*") ?: [] as $file) {
                is_dir($file) ? $this->removeTree($file) : unlink($file);
            }
            @rmdir($directory);
        }
    }

    // ── schema ─────────────────────────────────────────────────────────────

    public function testTablesAreCreatedAndInspected(): void
    {
        $schema = $this->db->schema();
        $schema->create('patients', static function (Blueprint $t): void {
            $t->id();
            $t->uuid('reference')->unique();
            $t->string('name', 100);
            $t->integer('age')->nullable();
            $t->boolean('active')->default(true);
            $t->decimal('weight', 5, 1)->nullable();
            $t->timestamps();
        });
        $schema->create('measurements', static function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('patient_id');
            $t->float('bmi');
            $t->json('details')->nullable();
            $t->date('taken_on');
            $t->text('note')->default("it's fine");
            $t->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
            $t->index(['patient_id', 'taken_on']);
        });

        $this->assertTrue($schema->hasTable('patients'));
        $this->assertTrue($schema->hasColumn('patients', 'reference'));
        $this->assertFalse($schema->hasColumn('patients', 'missing'));

        $this->db->insert('patients', ['reference' => 'r-1', 'name' => 'Ada', 'created_at' => '2026-10-24 10:00:00']);
        $this->assertSame(1, $this->db->scalar('SELECT active FROM patients'), 'defaults apply');
        $this->db->insert('measurements', ['patient_id' => 1, 'bmi' => 22.5, 'taken_on' => '2026-10-24']);
        $this->assertSame("it's fine", $this->db->scalar('SELECT note FROM measurements'));

        $this->db->table('patients')->where('id', 1)->delete();
        $this->assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM measurements'), 'ON DELETE CASCADE');

        try {
            $this->db->insert('patients', ['reference' => 'r-1', 'name' => 'Duplicate']);
            $this->db->insert('patients', ['reference' => 'r-1', 'name' => 'Duplicate']);
            $this->fail('UNIQUE must be enforced.');
        } catch (DatabaseException) {
        }

        $schema->drop('measurements');
        $schema->dropIfExists('measurements');
        $this->assertFalse($schema->hasTable('measurements'));
    }

    public function testDdlPerGrammar(): void
    {
        $blueprint = new Blueprint('patients');
        $blueprint->id();
        $blueprint->string('name')->default('n/a');
        $blueprint->boolean('active')->default(false);
        $blueprint->dateTime('seen_at')->nullable();
        $blueprint->bigInteger('clinic_id');
        $blueprint->foreign('clinic_id')->on('clinics')->onDelete('set null');
        $blueprint->unique('name');

        $this->assertSame([
            'CREATE TABLE `patients` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) NOT NULL DEFAULT \'n/a\', `active` TINYINT(1) NOT NULL DEFAULT 0, `seen_at` DATETIME NULL, `clinic_id` BIGINT NOT NULL, FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE UNIQUE INDEX `patients_name_unique` ON `patients` (`name`)',
        ], (new MysqlGrammar())->compileCreate($blueprint));

        $this->assertSame(
            'CREATE TABLE "patients" ("id" BIGSERIAL PRIMARY KEY, "name" VARCHAR(255) NOT NULL DEFAULT \'n/a\', "active" BOOLEAN NOT NULL DEFAULT FALSE, "seen_at" TIMESTAMP(0) NULL, "clinic_id" BIGINT NOT NULL, FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL)',
            (new PostgresGrammar())->compileCreate($blueprint)[0]
        );
        $this->assertFalse((new MysqlGrammar())->supportsTransactionalDdl());
    }

    public function testTablesAreAlteredInPlace(): void
    {
        $schema = $this->db->schema();
        $schema->create('measurements', static function (Blueprint $t): void {
            $t->id();
            $t->float('bmi');
            $t->string('legacy_code', 10)->nullable();
            $t->index('legacy_code');
        });
        $this->db->table('measurements')->insert(['bmi' => 22.5, 'legacy_code' => 'A1']);

        $schema->table('measurements', static function (Blueprint $t): void {
            $t->dropIndex('measurements_legacy_code_index');
            $t->dropColumn('legacy_code');
            $t->uuid('patient_id')->nullable();
            $t->string('source', 10)->default('api');
            $t->string('external_ref', 20)->nullable()->unique();
            $t->index('patient_id');
        });

        $this->assertFalse($schema->hasColumn('measurements', 'legacy_code'));
        $this->assertTrue($schema->hasColumn('measurements', 'patient_id'));
        $this->assertSame([['bmi' => 22.5, 'patient_id' => null, 'source' => 'api', 'external_ref' => null]], $this->db->select('SELECT bmi, patient_id, source, external_ref FROM measurements'), 'existing rows get null or the default');

        $this->db->table('measurements')->insert(['bmi' => 30.1, 'external_ref' => 'X']);
        try {
            $this->db->table('measurements')->insert(['bmi' => 30.2, 'external_ref' => 'X']);
            $this->fail('The added unique column must be unique.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $schema->table('measurements', static fn (Blueprint $t) => $t->dropIndex('measurements_patient_id_index'));
        $schema->table('measurements', static fn (Blueprint $t) => $t->dropColumn('patient_id', 'source'));
        $this->assertFalse($schema->hasColumn('measurements', 'source'));
    }

    public function testAlterSqlPerGrammar(): void
    {
        $blueprint = new Blueprint('measurements');
        $blueprint->dropIndex('measurements_code_index');
        $blueprint->dropColumn('code');
        $blueprint->uuid('patient_id')->nullable();
        $blueprint->boolean('archived')->default(false);
        $blueprint->index('patient_id');

        $this->assertSame([
            'DROP INDEX `measurements_code_index` ON `measurements`',
            'ALTER TABLE `measurements` DROP COLUMN `code`',
            'ALTER TABLE `measurements` ADD COLUMN `patient_id` CHAR(36) NULL',
            'ALTER TABLE `measurements` ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0',
            'CREATE INDEX `measurements_patient_id_index` ON `measurements` (`patient_id`)',
        ], (new MysqlGrammar())->compileAlter($blueprint));

        $this->assertSame([
            'DROP INDEX "measurements_code_index"',
            'ALTER TABLE "measurements" DROP COLUMN "code"',
            'ALTER TABLE "measurements" ADD COLUMN "patient_id" UUID NULL',
            'ALTER TABLE "measurements" ADD COLUMN "archived" BOOLEAN NOT NULL DEFAULT FALSE',
            'CREATE INDEX "measurements_patient_id_index" ON "measurements" ("patient_id")',
        ], (new PostgresGrammar())->compileAlter($blueprint));
    }

    public function testAlterRefusesChangesExistingTablesCannotTake(): void
    {
        $this->db->schema()->create('t', static fn (Blueprint $b) => $b->id());

        foreach ([
            'must be nullable or have a default' => static fn (Blueprint $b) => $b->string('name'),
            'primary key cannot be added' => static fn (Blueprint $b) => $b->id('second'),
            'primary key cannot be added ' => static fn (Blueprint $b) => $b->uuid('ref')->nullable()->primary(),
            'Foreign keys cannot be added' => static fn (Blueprint $b) => $b->foreign('x')->on('y'),
        ] as $message => $define) {
            try {
                $this->db->schema()->table('t', $define);
                $this->fail("Expected: {$message}");
            } catch (\LogicException $e) {
                $this->assertStringContainsString(trim($message), $e->getMessage());
            }
        }
    }

    public function testForeignKeysNeedATable(): void
    {
        $this->expectException(\LogicException::class);

        $this->db->schema()->create('x', static function (Blueprint $t): void {
            $t->integer('a');
            $t->foreign('a');
        });
    }

    // ── migrations ─────────────────────────────────────────────────────────

    public function testMigrationsRunInOrderAcrossPathsAndRollBackByBatch(): void
    {
        $app = $this->directory();
        $module = $this->directory();
        $this->migration($app, '2026_01_01_000000_create_patients', 'patients');
        $this->migration($module, '2026_01_02_000000_create_measurements', 'measurements');

        $migrator = $this->migrator([$app, $module]);
        $this->assertSame(['2026_01_01_000000_create_patients', '2026_01_02_000000_create_measurements'], $migrator->migrate());
        $this->assertSame([], $migrator->migrate(), 'nothing left');

        $this->migration($app, '2026_02_01_000000_create_clinics', 'clinics');
        $this->assertSame(['2026_02_01_000000_create_clinics'], $migrator->migrate());

        $this->assertSame([
            ['migration' => '2026_01_01_000000_create_patients', 'ran' => true, 'batch' => 1],
            ['migration' => '2026_01_02_000000_create_measurements', 'ran' => true, 'batch' => 1],
            ['migration' => '2026_02_01_000000_create_clinics', 'ran' => true, 'batch' => 2],
        ], $migrator->status());

        $this->assertSame(['2026_02_01_000000_create_clinics'], $migrator->rollback());
        $this->assertFalse($this->db->schema()->hasTable('clinics'));
        $this->assertSame(['2026_01_02_000000_create_measurements', '2026_01_01_000000_create_patients'], $migrator->reset());
        $this->assertFalse($this->db->schema()->hasTable('patients'));
        $this->assertSame([], $migrator->rollback());
    }

    public function testFailedBatchesAreRolledBack(): void
    {
        $dir = $this->directory();
        $this->migration($dir, '2026_01_01_000000_create_patients', 'patients');
        file_put_contents("{$dir}/2026_01_02_000000_broken.php", '<?php use Zana\Database\Migration\Migration; use Zana\Database\Schema\Schema; return new class () extends Migration { public function up(Schema $s): void { throw new \RuntimeException("disk full"); } public function down(Schema $s): void {} };');

        try {
            $this->migrator([$dir])->migrate();
            $this->fail('A failing migration must throw.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Migration 2026_01_02_000000_broken failed (up): disk full', $e->getMessage());
        }

        $this->assertFalse($this->db->schema()->hasTable('patients'), 'the whole batch rolled back');
        $this->assertSame([], (new MigrationRepository($this->db))->ran());
    }

    public function testInvalidMigrationFiles(): void
    {
        $badName = $this->directory();
        file_put_contents("{$badName}/CreatePatients.php", '<?php return null;');
        $badContent = $this->directory();
        file_put_contents("{$badContent}/2026_01_01_000000_nothing.php", '<?php return 42;');
        $first = $this->directory();
        $second = $this->directory();
        $this->migration($first, '2026_01_01_000000_twice', 'a');
        $this->migration($second, '2026_01_01_000000_twice', 'b');

        foreach ([[$badName], [$badContent], [$first, $second]] as $paths) {
            try {
                $this->migrator($paths)->migrate();
                $this->fail('Invalid migrations must be rejected.');
            } catch (MigrationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── provider and commands ──────────────────────────────────────────────

    public function testTheProviderAndCommands(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
        mkdir("{$base}/database/migrations", 0777, true);
        $this->migration("{$base}/database/migrations", '2026_01_01_000000_create_patients', 'patients');

        $container = Application::configure($base)
            ->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s)
            ->build()
            ->container();
        $kernel = $container->get(ConsoleKernel::class);

        $this->assertStringContainsString('Ran 1 migration', $this->console($kernel, ['command' => 'migrate']));
        $this->assertStringContainsString('Nothing to migrate', $this->console($kernel, ['command' => 'migrate']));
        $this->assertStringContainsString('Ran', $this->console($kernel, ['command' => 'migrate:status']));
        $this->assertStringContainsString('Rolled back 1 migration', $this->console($kernel, ['command' => 'migrate:rollback', '--step' => '1']));
        $this->assertStringContainsString('--step must be a positive integer', $this->console($kernel, ['command' => 'migrate:rollback', '--step' => 'x']));
        $this->assertStringContainsString('Created', $this->console($kernel, ['command' => 'make:migration', 'name' => 'create_clinics_table']));
        $this->assertStringContainsString('may only contain', $this->console($kernel, ['command' => 'make:migration', 'name' => 'Bad Name']));

        $created = glob("{$base}/database/migrations/*_create_clinics_table.php") ?: [];
        $this->assertCount(1, $created);
        $this->assertStringContainsString("create('clinics'", (string) file_get_contents($created[0]));
        $this->assertStringContainsString('Ran 2 migrations', $this->console($kernel, ['command' => 'migrate']), 'the generated migration runs');

        $scope = $container->createScope();
        $this->assertInstanceOf(TransactionalUnitOfWork::class, $scope->get(UnitOfWorkInterface::class));
        $this->assertSame($container->get(ConnectionInterface::class), $scope->get(ConnectionInterface::class));
    }

    public function testInspectionAndChecksSeeTheMigrations(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
        mkdir("{$base}/database/migrations", 0777, true);
        $this->migration("{$base}/database/migrations", '2026_01_01_000000_create_patients', 'patients');
        $app = Application::configure($base)->withProviders(DatabaseServiceProvider::class)->build();
        $section = $app->container()->get(MigrationsSection::class);
        $check = $app->container()->get(MigrationsCheck::class);
        assert($section instanceof MigrationsSection && $check instanceof MigrationsCheck);

        $this->assertSame([['migration' => '2026_01_01_000000_create_patients', 'ran' => false, 'batch' => null]], $section->inspect($app));
        $findings = $check->findings($app);
        $this->assertSame(['database.pending', Severity::Warning, 'Run: php bin/console migrate'], [$findings[0]->id, $findings[0]->severity, $findings[0]->hint]);

        $migrator = $app->container()->get(Migrator::class);
        assert($migrator instanceof Migrator);
        $migrator->migrate();
        $afterMigrating = $check->findings($app);
        $this->assertSame([], $afterMigrating);

        unlink("{$base}/database/migrations/2026_01_01_000000_create_patients.php");
        $afterDeletion = $check->findings($app);
        $this->assertSame('database.missing_file', $afterDeletion[0]->id);
    }

    public function testAnUnreachableDatabaseIsAnError(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => " . var_export("{$base}/missing/folder/app.sqlite", true) . "]]];");
        $app = Application::configure($base)->withProviders(DatabaseServiceProvider::class)->build();
        $check = $app->container()->get(MigrationsCheck::class);
        assert($check instanceof MigrationsCheck);

        $findings = $check->findings($app);

        $this->assertSame(['database.migrations', Severity::Error], [$findings[0]->id, $findings[0]->severity]);
        $this->assertStringStartsWith('The migrations cannot be checked: ', $findings[0]->message);
    }

    /**
     * @param array<string, string> $input
     */
    private function console(ConsoleKernel $kernel, array $input): string
    {
        $output = new BufferedOutput();
        $kernel->run(new ArrayInput($input), $output);

        return $output->fetch();
    }

    /**
     * @param list<string> $paths
     */
    private function migrator(array $paths): Migrator
    {
        return new Migrator($this->db, new MigrationRepository($this->db), $paths);
    }

    private function migration(string $directory, string $name, string $table): void
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

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/zana-db-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->directories[] = $directory;

        return $directory;
    }

    private function removeTree(string $directory): void
    {
        foreach (glob("{$directory}/*") ?: [] as $file) {
            is_dir($file) ? $this->removeTree($file) : unlink($file);
        }
        rmdir($directory);
    }
}
