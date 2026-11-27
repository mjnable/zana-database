<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Core\Application;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseServiceProvider;
use Zana\Database\Migration\MigrationException;
use Zana\Database\Migration\MigrationRepository;
use Zana\Database\Migration\Migrator;

/**
 * FE-85: two migrate runs on one database are serialized. Each case runs real processes at the same moment, on a
 * SQLite file, MySQL (and MariaDB, through ZANA_TEST_MYSQL_PORT) and PostgreSQL.
 */
final class MigrationLockTest extends TestCase
{
    private const array TABLES = ['lock_rows', 'lock_made', 'lock_extra', 'zana_migrations'];

    /** @var list<string> */
    private array $directories = [];

    /** @var list<resource> */
    private array $processes = [];

    /** @var array<int, array<int, resource>> process => its pipes */
    private array $pipes = [];

    private string $namespace = '';

    protected function setUp(): void
    {
        $this->namespace = 'Zana\\Database\\Tests\\Lock\\L' . bin2hex(random_bytes(5));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return Drivers::names();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function races(): iterable
    {
        foreach (array_keys(Drivers::names()) as $driver) {
            yield "{$driver}, transactional" => [$driver, 'transactional', 'dbal'];
            yield "{$driver}, non-transactional" => [$driver, 'non-transactional', 'dbal'];
            yield "{$driver}, the 2.36 engine" => [$driver, 'schema', 'pdo'];
        }
    }

    // ── two runs at once ───────────────────────────────────────────────────

    /**
     * The race FE-85 recorded on 2.38: a non-transactional migration ran twice. Now one run waits for the other, reads
     * what is pending once it holds the lock, finds nothing, and both succeed.
     */
    #[DataProvider('races')]
    public function testTwoRunsAtOnceRunEachMigrationOnce(string $driver, string $kind, string $engine): void
    {
        [$settings, $db, $dir] = $this->released($driver, $engine);
        $name = match ($kind) {
            'schema' => $this->slowZanaFile($dir),
            default => $this->slowDoctrineClass($dir, 'Version20260102000000', $kind === 'transactional'),
        };

        $results = $this->together($settings, $dir, 60, $engine, 2);

        $this->assertSame([[$name], []], self::sorted(array_column($results, 'ran')), json_encode($results) ?: '');
        $this->assertSame(1, self::times($db, 'zana_migrations', $name), 'recorded once');
        if ($kind !== 'schema') {
            $this->assertSame(1, $db->table('lock_rows')->count(), 'the row is added once');
        }
    }

    /**
     * On MySQL and MariaDB the migrator closes its connection after a schema change commits a transaction
     * (ImplicitCommits): the lock is on a connection of its own, so it is still held after that.
     */
    #[DataProvider('drivers')]
    public function testTheLockIsStillHeldAfterASchemaChangeInTheRun(string $driver): void
    {
        [$settings, $db, $dir] = $this->released($driver, 'dbal');
        $this->doctrineClass($dir, 'Version20260102000000', "\$this->addSql('CREATE TABLE lock_extra (id INT)');", down: "\$this->addSql('DROP TABLE lock_extra');");
        $slow = $this->slowDoctrineClass($dir, 'Version20260103000000', false);

        $first = $this->start($settings, ['migrate', $dir, '60', 'dbal']);
        usleep(1_200_000);   // the first run is past its schema change, in the slow migration
        $second = $this->start($settings, ['migrate', $dir, '60', 'dbal']);
        $results = [$this->finish($first), $this->finish($second)];

        $this->assertSame([], $results[1]['ran'] ?? null, json_encode($results) ?: '');
        $this->assertContains($slow, $results[0]['ran'] ?? []);
        $this->assertSame(1, $db->table('lock_rows')->count());
    }

    // ── waiting ────────────────────────────────────────────────────────────

    #[DataProvider('drivers')]
    public function testATimeoutOfZeroStopsAtOnceAndChangesNothing(string $driver): void
    {
        [$settings, $db, $dir] = $this->released($driver, 'dbal');
        $this->slowDoctrineClass($dir, 'Version20260102000000', false);
        $holder = $this->hold($settings, 10);
        $before = $this->recorded($db);

        $start = microtime(true);
        try {
            $this->migrator($db, $dir, 0)->migrate();
            $this->fail('The run must stop.');
        } catch (MigrationException $e) {
            $this->assertSame('Another migrate is running on this database, and database.migrations.lock_timeout is 0, so this one does not wait. Nothing was run.', $e->getMessage());
        }

        $this->assertLessThan(2.0, microtime(true) - $start, 'it did not wait');
        $this->assertSame($before, $this->recorded($db), 'nothing was recorded');
        $this->assertSame(0, $db->table('lock_rows')->count(), 'nothing ran');
        proc_terminate($holder);
    }

    #[DataProvider('drivers')]
    public function testARunWaitsUpToItsTimeoutThenStopsAndChangesNothing(string $driver): void
    {
        [$settings, $db, $dir] = $this->released($driver, 'dbal');
        $this->slowDoctrineClass($dir, 'Version20260102000000', false);
        $holder = $this->hold($settings, 15);
        $before = $this->recorded($db);

        $start = microtime(true);
        try {
            $this->migrator($db, $dir, 2)->migrate();
            $this->fail('The run must stop.');
        } catch (MigrationException $e) {
            $this->assertSame('Another migrate is running on this database: its lock was not released within 2 seconds (database.migrations.lock_timeout). Nothing was run.', $e->getMessage());
        }

        $elapsed = microtime(true) - $start;
        $this->assertGreaterThanOrEqual(1.9, $elapsed, 'it waited');
        $this->assertLessThan(8.0, $elapsed);
        $this->assertSame($before, $this->recorded($db));
        $this->assertSame(0, $db->table('lock_rows')->count());
        proc_terminate($holder);
    }

    #[DataProvider('drivers')]
    public function testARunThatWaitsGoesAheadWhenTheLockIsReleased(string $driver): void
    {
        [$settings, $db, $dir] = $this->released($driver, 'dbal');
        $name = $this->slowDoctrineClass($dir, 'Version20260102000000', false);
        $this->hold($settings, 2);

        $start = microtime(true);
        $this->assertSame([$name], $this->migrator($db, $dir, 30)->migrate());

        $this->assertGreaterThan(0.5, microtime(true) - $start, 'it waited for the holder');
        $this->assertSame(1, $db->table('lock_rows')->count());
    }

    // ── recovery ───────────────────────────────────────────────────────────

    #[DataProvider('drivers')]
    public function testTheLockOfAKilledProcessIsReleased(string $driver): void
    {
        [$settings, $db, $dir] = $this->released($driver, 'dbal');
        $name = $this->slowDoctrineClass($dir, 'Version20260102000000', false);
        $holder = $this->hold($settings, 120);

        proc_terminate($holder, 9);
        proc_close($holder);

        $this->assertSame([$name], $this->migrator($db, $dir, 20)->migrate(), 'the next run proceeds');
    }

    #[DataProvider('drivers')]
    public function testTheLockIsReleasedWhenAMigrationFails(string $driver): void
    {
        [, $db, $dir] = $this->released($driver, 'dbal');
        $this->doctrineClass($dir, 'Version20260102000000', "\$this->addSql('UPDATE lock_missing SET x = 1');");

        try {
            $this->migrator($db, $dir, 0)->migrate();
            $this->fail('The migration must fail.');
        } catch (MigrationException $e) {
            $this->assertStringStartsWith("Migration {$this->namespace}\\Version20260102000000 failed (up): ", $e->getMessage());
        }

        unlink("{$dir}/Version20260102000000.php");
        $name = $this->slowDoctrineClass($dir, 'Version20260103000000', false);
        $this->assertSame([$name], $this->migrator($db, $dir, 0)->migrate(), 'taken again at once');
    }

    /**
     * A failure while releasing the lock does not hide the migration's own: here the migration kills the session that
     * holds the lock, then fails.
     */
    public function testReleasingTheLockNeverHidesTheMigrationsFailure(): void
    {
        $kills = [
            // MySQL 9 has no MD5() function: the lock's name is computed here, as MigrationLock computes it.
            'mysql' => "\$this->connection->executeStatement('KILL ' . (int) \$this->connection->fetchOne('SELECT IS_USED_LOCK(?)', ['zana_migrate_' . md5((string) \$this->connection->fetchOne('SELECT DATABASE()'))]));",
            'pgsql' => "\$this->connection->executeQuery('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid()');",
        ];
        foreach ($kills as $driver => $kill) {
            $settings = Drivers::settings($driver);
            if (is_string($settings)) {
                continue;
            }
            [, $db, $dir] = $this->released($driver, 'dbal');
            $this->doctrineClass($dir, 'Version20260102000000', "{$kill}\nthrow new \\RuntimeException('the migration failed');");

            try {
                $this->migrator($db, $dir, 0)->migrate();
                $this->fail('The migration must fail.');
            } catch (MigrationException $e) {
                $this->assertStringContainsString('the migration failed', $e->getMessage(), $driver);
            }
            $this->namespace .= 'x';
        }
        $this->addToAssertionCount(1);
    }

    // ── which databases share a lock ───────────────────────────────────────

    #[DataProvider('drivers')]
    public function testTwoDatabasesDoNotShareTheLock(string $driver): void
    {
        [$settings] = $this->released($driver, 'dbal');
        $holder = $this->hold($settings, 10);
        [$other, $otherDb] = $this->otherDatabase($driver);
        $dir = $this->directory();
        $name = $this->slowDoctrineClass($dir, 'Version20260102000000', false, table: false);

        $this->assertSame([$name], $this->migrator($otherDb, $dir, 0)->migrate(), 'another database is not locked');
        proc_terminate($holder);
        unset($other);
    }

    /**
     * The lock is the database's, whatever the run: another directory of migrations, another migrator, the 2.36 engine,
     * or another way of writing the host.
     */
    #[DataProvider('drivers')]
    public function testEveryRunOnOneDatabaseSharesTheLock(string $driver): void
    {
        [$settings, $db] = $this->released($driver, 'dbal');
        $holder = $this->hold($settings, 10);
        $refused = static function (callable $run): string {
            try {
                $run();

                return 'ran';
            } catch (MigrationException $e) {
                return str_starts_with($e->getMessage(), 'Another migrate is running on this database') ? 'refused' : $e->getMessage();
            }
        };

        $this->assertSame('refused', $refused(fn () => $this->migrator($db, $this->directory(), 0)->migrate()));
        $this->assertSame('refused', $refused(fn () => $this->migrator($db, $this->directory(), 0)->rollback()));
        $this->assertSame('refused', $refused(fn () => $this->migrator($db, $this->directory(), 0)->reset()));
        $legacy = ConnectionFactory::createPdo($settings);
        $this->assertSame('refused', $refused(fn () => (new Migrator($legacy, new MigrationRepository($legacy), [$this->directory()], lockTimeout: 0))->migrate()), 'the 2.36 engine');
        if ($driver === 'mysql') {
            // On Linux, "localhost" means the server's socket, which a server in a container may not offer.
            try {
                $localhost = ConnectionFactory::create(['host' => 'localhost'] + $settings);
            } catch (\Zana\Database\DatabaseException) {
                $localhost = null;
            }
            if ($localhost !== null) {
                $this->assertSame('refused', $refused(fn () => $this->migrator($localhost, $this->directory(), 0)->migrate()), 'localhost and 127.0.0.1');
            }
        }
        proc_terminate($holder);
    }

    // ── one lock for a whole operation ─────────────────────────────────────

    #[DataProvider('drivers')]
    public function testAResetHoldsTheLockFromItsFirstDownToItsLast(string $driver): void
    {
        [$settings, $db, $dir] = $this->released($driver, 'dbal');
        $this->doctrineClass($dir, 'Version20260102000000', "\$this->addSql('CREATE TABLE lock_extra (id INT)');", down: "usleep(1_500_000);\n\$this->addSql('DROP TABLE lock_extra');");
        $this->doctrineClass($dir, 'Version20260103000000', "\$this->addSql('INSERT INTO lock_rows (note) VALUES (?)', ['x']);", down: "usleep(1_500_000);\n\$this->addSql('DELETE FROM lock_rows');");
        $this->migrator($db, $dir, 0)->migrate();

        $reset = $this->start($settings, ['reset', $dir]);
        usleep(1_000_000);   // inside the first down()
        $start = microtime(true);
        $this->assertSame([], $this->migrator($db, $dir, 30)->rollback(), 'it waited for the whole reset, then found nothing to roll back');
        $this->assertGreaterThan(1.0, microtime(true) - $start);
        $this->finish($reset);
    }

    // ── the setting ────────────────────────────────────────────────────────

    public function testTheTimeoutComesFromConfigurationAndMustNotBeNegative(): void
    {
        $this->expectExceptionMessage('database.migrations.lock_timeout must be 0 or more seconds, not -1.');
        new Migrator(ConnectionFactory::create(['driver' => 'sqlite', 'database' => ':memory:']), new MigrationRepository(ConnectionFactory::create(['driver' => 'sqlite', 'database' => ':memory:'])), [], lockTimeout: -1);
    }

    public function testTheProviderReadsTheTimeout(): void
    {
        $base = $this->directory();
        mkdir("{$base}/config");
        $file = "{$base}/app.sqlite";
        file_put_contents("{$base}/config/database.php", "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => " . var_export($file, true) . "]], 'migrations' => ['lock_timeout' => 0]];");
        mkdir("{$base}/database/migrations", 0777, true);
        $kernel = Application::configure($base)->withProviders(ConsoleServiceProvider::class, DatabaseServiceProvider::class)->build()->container()->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);
        $holder = $this->hold(['driver' => 'sqlite', 'database' => $file], 10);

        $output = new BufferedOutput();
        $status = $kernel->run(new ArrayInput(['command' => 'migrate']), $output);

        $this->assertNotSame(0, $status);
        // The console wraps the message at its width, even inside a word: compared without any whitespace.
        $this->assertStringContainsString('lock_timeoutis0', (string) preg_replace('/\s+/', '', $output->fetch()));
        proc_terminate($holder);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    #[After]
    public function cleanUp(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
        foreach ($this->directories as $directory) {
            $this->removeTree($directory);
        }
    }

    /**
     * A database migrated by an earlier release: lock_rows made by a Zana file, in batch 1. The directory then gets
     * this release's migrations.
     *
     * @return array{array<string, mixed>, ConnectionInterface, string}
     */
    private function released(string $driver, string $engine): array
    {
        $settings = Drivers::settings($driver, $driver === 'sqlite' ? $this->directory() . '/app.sqlite' : null);
        if (is_string($settings)) {
            $this->markTestSkipped($settings);
        }
        $db = ConnectionFactory::create($settings);
        foreach (self::TABLES as $table) {
            $db->schema()->dropIfExists($table);
        }
        $dir = $this->directory();
        file_put_contents("{$dir}/2026_01_01_000000_create_lock_rows.php", <<<'PHP'
            <?php
            use Zana\Database\Migration\Migration;
            use Zana\Database\Schema\Blueprint;
            use Zana\Database\Schema\Schema;
            return new class () extends Migration {
                public function up(Schema $schema): void { $schema->create('lock_rows', static function (Blueprint $t): void { $t->id(); $t->string('note', 40); }); }
                public function down(Schema $schema): void { $schema->drop('lock_rows'); }
            };
            PHP);
        $migrator = $engine === 'pdo' ? new Migrator($legacy = ConnectionFactory::createPdo($settings), new MigrationRepository($legacy), [$dir]) : $this->migrator($db, $dir, 0);
        $migrator->migrate();

        return [$settings, $db, $dir];
    }

    /**
     * A second database on the same server, empty.
     *
     * @return array{array<string, mixed>, ConnectionInterface}
     */
    private function otherDatabase(string $driver): array
    {
        if ($driver === 'sqlite') {
            $settings = ['driver' => 'sqlite', 'database' => $this->directory() . '/other.sqlite'];

            return [$settings, ConnectionFactory::create($settings)];
        }
        $settings = Drivers::settings($driver);
        assert(is_array($settings));
        $server = ConnectionFactory::create($settings);
        $name = Drivers::DATABASE . '_other';
        $server->execute("DROP DATABASE IF EXISTS {$name}" . ($driver === 'pgsql' ? ' WITH (FORCE)' : ''));
        $server->execute("CREATE DATABASE {$name}");
        $other = ['database' => $name] + $settings;

        return [$other, ConnectionFactory::create($other)];
    }

    private function slowZanaFile(string $dir): string
    {
        $name = '2026_01_02_000000_create_lock_made';
        file_put_contents("{$dir}/{$name}.php", <<<'PHP'
            <?php
            use Zana\Database\Migration\Migration;
            use Zana\Database\Schema\Blueprint;
            use Zana\Database\Schema\Schema;
            return new class () extends Migration {
                public function up(Schema $schema): void { usleep(2_000_000); $schema->create('lock_made', static function (Blueprint $t): void { $t->id(); }); }
                public function down(Schema $schema): void { $schema->drop('lock_made'); }
            };
            PHP);

        return $name;
    }

    /**
     * A migration that takes two seconds, then adds a row to lock_rows (or only waits, without the table).
     */
    private function slowDoctrineClass(string $dir, string $class, bool $transactional, bool $table = true): string
    {
        $this->doctrineClass($dir, $class, "usleep(2_000_000);\n" . ($table ? "\$this->addSql('INSERT INTO lock_rows (note) VALUES (?)', ['added by the release']);" : "\$this->addSql('SELECT 1');"), transactional: $transactional);

        return "{$this->namespace}\\{$class}";
    }

    private function doctrineClass(string $directory, string $class, string $up, ?string $down = null, bool $transactional = true): void
    {
        $method = static fn (string $name, string $body): string => "    public function {$name}(Schema \$schema): void\n    {\n        " . str_replace("\n", "\n        ", $body) . "\n    }\n";
        file_put_contents("{$directory}/{$class}.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$this->namespace};\n\nuse Doctrine\\DBAL\\Schema\\Schema;\nuse Doctrine\\Migrations\\AbstractMigration;\n\nfinal class {$class} extends AbstractMigration\n{\n"
            . ($transactional ? '' : "    public function isTransactional(): bool\n    {\n        return false;\n    }\n")
            . $method('up', $up) . ($down === null ? '' : $method('down', $down)) . "}\n");
    }

    private function migrator(ConnectionInterface $db, string $dir, int $timeout): Migrator
    {
        return new Migrator($db, new MigrationRepository($db), [$dir], lockTimeout: $timeout);
    }

    /**
     * $count migrate runs started at the same moment, each in its own process.
     *
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>
     */
    private function together(array $settings, string $dir, int $timeout, string $engine, int $count): array
    {
        $processes = [];
        foreach (range(1, $count) as $_) {
            $processes[] = $this->start($settings, ['migrate', $dir, (string) $timeout, $engine]);
        }

        return array_map($this->finish(...), $processes);
    }

    /**
     * A process that holds the lock for $seconds; returns once it holds it.
     *
     * @param array<string, mixed> $settings
     * @return resource
     */
    private function hold(array $settings, int $seconds)
    {
        $process = $this->start($settings, ['hold', (string) $seconds]);
        $pipes = $this->pipes[(int) $process];
        $line = fgets($pipes[1]);
        if ($line !== "locked\n") {
            // Read only now: reading the holder's errors waits for it to end.
            $this->fail('The holder did not take the lock: ' . $line . stream_get_contents($pipes[2]));
        }

        return $process;
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<string> $arguments
     * @return resource
     */
    private function start(array $settings, array $arguments)
    {
        $php = [PHP_BINARY, '-d', 'xdebug.mode=off'];
        if (($settings['driver'] ?? '') === 'pgsql' && extension_loaded('pdo_pgsql')) {
            exec(escapeshellarg(PHP_BINARY) . ' -r "echo extension_loaded(\'pdo_pgsql\') ? 1 : 0;"', $loaded);
            if (($loaded[0] ?? '1') === '0') {
                array_push($php, '-d', 'extension=pdo_pgsql');
            }
        }
        if (($arguments[0] ?? '') === 'reset') {
            $script = $this->directory() . '/reset.php';
            file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; $s = json_decode($argv[1], true); $db = Zana\Database\ConnectionFactory::create($s); echo json_encode(["reset" => (new Zana\Database\Migration\Migrator($db, new Zana\Database\Migration\MigrationRepository($db), [$argv[2]]))->reset()]);');
            $command = [...$php, $script, json_encode($settings, JSON_THROW_ON_ERROR), $arguments[1]];
        } else {
            $command = [...$php, __DIR__ . '/migrate-worker.php', json_encode($settings, JSON_THROW_ON_ERROR), ...$arguments];
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert(is_resource($process));
        $this->processes[] = $process;
        $this->pipes[(int) $process] = $pipes;

        return $process;
    }

    /**
     * What the process printed, once it has ended.
     *
     * @param resource $process
     * @return array<string, mixed>
     */
    private function finish($process): array
    {
        $pipes = $this->pipes[(int) $process] ?? [];
        $output = isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
        $errors = isset($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
        proc_close($process);
        $result = json_decode($output, true);

        return is_array($result) ? $result : ['output' => $output . $errors];
    }

    /**
     * @return array<string, int>
     */
    private function recorded(ConnectionInterface $db): array
    {
        return (new MigrationRepository($db))->ran();
    }

    private static function times(ConnectionInterface $db, string $table, string $migration): int
    {
        return $db->table($table)->where('migration', $migration)->count();
    }

    /**
     * @param list<mixed> $lists
     * @return list<mixed>
     */
    private static function sorted(array $lists): array
    {
        usort($lists, static fn ($a, $b): int => count((array) $b) <=> count((array) $a));

        return $lists;
    }

    private function directory(): string
    {
        $directory = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-lock-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->directories[] = $directory;

        return $directory;
    }

    private function removeTree(string $directory): void
    {
        foreach (glob("{$directory}/*") ?: [] as $file) {
            is_dir($file) ? $this->removeTree($file) : @unlink($file);
        }
        @rmdir($directory);
    }
}
