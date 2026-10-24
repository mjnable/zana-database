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
use Zana\Database\Connection;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseException;
use Zana\Database\DatabaseServiceProvider;
use Zana\Database\Grammar\MysqlGrammar;
use Zana\Database\Grammar\PostgresGrammar;
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
            'CREATE TABLE `patients` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) NOT NULL DEFAULT \'n/a\', `active` TINYINT(1) NOT NULL DEFAULT 0, `seen_at` DATETIME NULL, `clinic_id` BIGINT NOT NULL, FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE SET NULL)',
            'CREATE UNIQUE INDEX `patients_name_unique` ON `patients` (`name`)',
        ], (new MysqlGrammar())->compileCreate($blueprint));

        $this->assertSame(
            'CREATE TABLE "patients" ("id" BIGSERIAL PRIMARY KEY, "name" VARCHAR(255) NOT NULL DEFAULT \'n/a\', "active" BOOLEAN NOT NULL DEFAULT FALSE, "seen_at" TIMESTAMP(0) NULL, "clinic_id" BIGINT NOT NULL, FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL)',
            (new PostgresGrammar())->compileCreate($blueprint)[0]
        );
        $this->assertFalse((new MysqlGrammar())->supportsTransactionalDdl());
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
