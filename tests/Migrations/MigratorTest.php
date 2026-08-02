<?php

declare(strict_types=1);

namespace Zana\Database\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Zana\Database\Connections\PDOConnection;
use Zana\Database\Migrations\Migration;
use Zana\Database\Migrations\MigrationRepository;
use Zana\Database\Migrations\Migrator;
use Zana\Database\Contracts\ConnectionInterface;

class MigratorTest extends TestCase
{
    private PDOConnection $connection;
    private Migrator $migrator;
    private string $migrationPath;

    protected function setUp(): void
    {
        $this->connection = new PDOConnection('sqlite::memory:', '', '', []);
        $this->migrationPath = sys_get_temp_dir() . '/zana_migrator_' . uniqid();

        if (!is_dir($this->migrationPath)) {
            mkdir($this->migrationPath, 0777, true);
        }

        $this->migrator = new Migrator(
            $this->connection,
            new MigrationRepository($this->connection),
            $this->migrationPath
        );
    }

    protected function tearDown(): void
    {
        $this->connection->close();

        if (is_dir($this->migrationPath)) {
            foreach (glob($this->migrationPath . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->migrationPath);
        }
    }

    private function writeMigration(string $filename, string $up, string $down): void
    {
        $stub = <<<'PHP'
<?php

declare(strict_types=1);

use Zana\Database\Contracts\ConnectionInterface;
use Zana\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(ConnectionInterface $connection): void
    {
        %s
    }

    public function down(ConnectionInterface $connection): void
    {
        %s
    }
};
PHP;

        file_put_contents(
            $this->migrationPath . '/' . $filename,
            sprintf($stub, $up, $down)
        );
    }

    public function testRunAppliesPendingMigrations(): void
    {
        $this->writeMigration(
            '2025_01_01_000000_create_test.php',
            '$connection->execute("CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)");',
            '$connection->execute("DROP TABLE test");'
        );

        $ran = $this->migrator->run();

        $this->assertCount(1, $ran);
        $this->assertContains('2025_01_01_000000_create_test.php', $ran);

        // Table was actually created.
        $result = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test'");
        $this->assertCount(1, $result);

        // Running again does nothing (idempotent).
        $this->assertEmpty($this->migrator->run());
    }

    public function testGetPendingMigrationsOnlyReturnsUnrun(): void
    {
        $this->writeMigration(
            '2025_01_01_000000_create_test.php',
            '$connection->execute("CREATE TABLE test (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE test");'
        );

        $this->migrator->run();
        $pending = $this->migrator->getPendingMigrations();

        $this->assertEmpty($pending);
    }

    public function testRollbackReversesLastBatch(): void
    {
        $this->writeMigration(
            '2025_01_01_000000_create_test.php',
            '$connection->execute("CREATE TABLE test (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE test");'
        );

        $this->migrator->run();

        // Table exists before rollback.
        $result = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test'");
        $this->assertCount(1, $result);

        $rolledBack = $this->migrator->rollback();

        $this->assertCount(1, $rolledBack);
        $this->assertContains('2025_01_01_000000_create_test.php', $rolledBack);

        // Table is gone after rollback.
        $result = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test'");
        $this->assertEmpty($result);
    }

    public function testRollbackWithNoMigrationsReturnsEmpty(): void
    {
        $this->assertEmpty($this->migrator->rollback());
    }

    public function testStatusReportsRanAndPending(): void
    {
        $this->writeMigration(
            '2025_01_01_000000_first.php',
            '$connection->execute("CREATE TABLE first (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE first");'
        );
        $this->writeMigration(
            '2025_01_01_000001_second.php',
            '$connection->execute("CREATE TABLE second (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE second");'
        );

        $this->migrator->run();

        $status = $this->migrator->status();

        $this->assertCount(2, $status);
        $this->assertSame('Ran', $status[0]['status']);
        $this->assertSame('Ran', $status[1]['status']);

        // Simulate a new pending migration.
        $this->writeMigration(
            '2025_01_01_000002_third.php',
            '$connection->execute("CREATE TABLE third (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE third");'
        );

        $status = $this->migrator->status();
        $this->assertCount(3, $status);
        $this->assertSame('Pending', $status[2]['status']);
    }

    public function testMakeCreatesMigrationFile(): void
    {
        $path = $this->migrator->make('create_users_table');

        $this->assertFileExists($path);
        $this->assertStringEndsWith('_create_users_table.php', $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('class extends Migration', $content);

        // The generated file is valid and returns a Migration instance.
        $migration = require $path;
        $this->assertInstanceOf(Migration::class, $migration);
    }

    public function testRunStepLimit(): void
    {
        $this->writeMigration(
            '2025_01_01_000000_first.php',
            '$connection->execute("CREATE TABLE first (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE first");'
        );
        $this->writeMigration(
            '2025_01_01_000001_second.php',
            '$connection->execute("CREATE TABLE second (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE second");'
        );

        $ran = $this->migrator->run(1);

        $this->assertCount(1, $ran);

        $status = $this->migrator->status();
        $this->assertSame('Ran', $status[0]['status']);
        $this->assertSame('Pending', $status[1]['status']);
    }

    public function testRollbackMultipleBatches(): void
    {
        $this->writeMigration(
            '2025_01_01_000000_first.php',
            '$connection->execute("CREATE TABLE first (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE first");'
        );
        $this->writeMigration(
            '2025_01_01_000001_second.php',
            '$connection->execute("CREATE TABLE second (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE second");'
        );

        // Run first migration in batch 1, then second in batch 2.
        $this->migrator->run(1);
        $this->migrator->run(1);

        $rolledBack = $this->migrator->rollback(2);

        $this->assertCount(2, $rolledBack);

        $result = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('first','second')");
        $this->assertEmpty($result);
    }

    public function testRepositoryUsesBatches(): void
    {
        $this->writeMigration(
            '2025_01_01_000000_first.php',
            '$connection->execute("CREATE TABLE first (id INTEGER PRIMARY KEY)");',
            '$connection->execute("DROP TABLE first");'
        );

        $this->migrator->run(1);

        $repository = new MigrationRepository($this->connection);
        $this->assertSame(1, $repository->getLastBatchNumber());
        $this->assertSame(2, $repository->getNextBatchNumber());
        $this->assertCount(1, $repository->getRan());
    }
}

