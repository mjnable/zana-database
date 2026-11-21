<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Configuration\Configuration;
use Zana\Database\Connection;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseException;
use Zana\Database\DatabaseManager;
use Zana\Database\TransactionalUnitOfWork;

final class ConnectionTest extends TestCase
{
    private ConnectionInterface $db;

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->execute('CREATE TABLE patients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER, active INTEGER)');
    }

    public function testQueriesWithPositionalAndNamedParameters(): void
    {
        $id = $this->db->insert('patients', ['name' => 'Ada', 'age' => 36, 'active' => true]);
        $this->db->insert('patients', ['name' => 'Grace', 'age' => 45, 'active' => false]);

        $this->assertSame('1', $id);
        $this->assertSame([['name' => 'Ada']], $this->db->select('SELECT name FROM patients WHERE age < ?', [40]));
        $this->assertSame(['name' => 'Grace', 'age' => 45], $this->db->first('SELECT name, age FROM patients WHERE name = :name', ['name' => 'Grace']));
        $this->assertNull($this->db->first('SELECT * FROM patients WHERE id = ?', [99]));
        $this->assertSame(2, $this->db->scalar('SELECT COUNT(*) FROM patients'));
        $this->assertNull($this->db->scalar('SELECT name FROM patients WHERE id = ?', [99]));
        $this->assertSame(2, $this->db->execute('UPDATE patients SET active = ?', [1]));
        $this->assertSame('sqlite', $this->db->driver());
    }

    public function testNestedTransactionsUseSavepoints(): void
    {
        $levels = [];
        $this->db->transaction(function (ConnectionInterface $db) use (&$levels): void {
            $db->insert('patients', ['name' => 'outer']);
            $levels[] = $db->transactionLevel();

            try {
                $db->transaction(static function (ConnectionInterface $db) use (&$levels): void {
                    $db->insert('patients', ['name' => 'inner']);
                    $levels[] = $db->transactionLevel();
                    self::fails('inner fails');
                });
            } catch (\RuntimeException) {
            }

            $levels[] = $db->transactionLevel();
        });

        $this->assertSame([1, 2, 1], $levels);
        $this->assertSame(0, $this->db->transactionLevel());
        $this->assertSame([['name' => 'outer']], $this->db->select('SELECT name FROM patients'));
    }

    public function testFailedTransactionsRollBackAndRethrow(): void
    {
        $thrown = null;
        try {
            $this->db->transaction(static function (ConnectionInterface $db): void {
                $db->insert('patients', ['name' => 'lost']);
                self::fails('rule violated');
            });
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the exception is rethrown');
        $this->assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM patients'));
    }

    private static function fails(string $message): void
    {
        if ($message !== '') {
            throw new \RuntimeException($message);
        }
    }

    public function testErrorsCarryTheSqlButNotTheValues(): void
    {
        try {
            $this->db->select('SELECT * FROM missing WHERE secret = ?', ['hunter2']);
            $this->fail('A failing query must throw.');
        } catch (DatabaseException $e) {
            $this->assertSame('SELECT * FROM missing WHERE secret = ?', $e->sql);
            $this->assertStringContainsString('no such table', $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function testAnArrayOrObjectIsNeverBoundAsTheTextArray(): void
    {
        // S33, on both connections: a form field sent as email[]=x arrives as an array.
        foreach (['dbal' => $this->db, 'pdo' => ConnectionFactory::createPdo(['driver' => 'sqlite', 'database' => ':memory:'])] as $kind => $db) {
            $db->execute('CREATE TABLE IF NOT EXISTS patients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER, active INTEGER)');
            $db->insert('patients', ['name' => 'Array', 'age' => 1]);
            foreach ([
                static fn () => $db->table('patients')->insert(['name' => ['x']]),
                static fn () => $db->table('patients')->where('id', 1)->update(['name' => ['a' => ['b' => 'secret-value']]]),
                static fn () => $db->table('patients')->where('name', ['x'])->get(),
                static fn () => $db->select('SELECT * FROM patients WHERE name = :name', ['name' => new \DateTimeImmutable()]),
                static fn () => $db->first('SELECT * FROM patients WHERE name = ?', [new \stdClass()]),
            ] as $i => $hostile) {
                try {
                    $hostile();
                    $this->fail("{$kind} #{$i}: an array or object was bound");
                } catch (DatabaseException $e) {
                    $this->assertStringContainsString('Cannot bind', $e->getMessage(), "{$kind} #{$i}");
                    $this->assertStringNotContainsString('secret-value', $e->getMessage(), 'values never appear');
                }
            }
            $this->assertSame(1, $db->table('patients')->count(), "{$kind}: nothing was written");
            $this->assertSame('Array', $db->table('patients')->value('name'), "{$kind}: the row is unchanged");

            $name = new class () implements \Stringable {
                public function __toString(): string
                {
                    return 'Array';
                }
            };
            $this->assertSame(1, $db->table('patients')->where('name', $name)->count(), "{$kind}: a Stringable binds as its string");
        }
    }

    public function testCommitWithoutTransactionFails(): void
    {
        $this->expectException(DatabaseException::class);
        $this->db->commit();
    }

    public function testUnknownDriversAndUnreachableServersAreReported(): void
    {
        try {
            ConnectionFactory::create(['driver' => 'oracle']);
            $this->fail('An unknown driver must fail.');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('Unknown database driver: "oracle"', $e->getMessage());
        }

        // A missing folder is made since 2.27.1, so: a folder that cannot be, under this file.
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot connect to the sqlite database');
        ConnectionFactory::create(['driver' => 'sqlite', 'database' => __FILE__ . '/dir/db.sqlite']);
    }

    public function testForeignKeysAreEnforcedOnSqlite(): void
    {
        $this->db->execute('CREATE TABLE measurements (id INTEGER PRIMARY KEY, patient_id INTEGER NOT NULL REFERENCES patients(id))');

        $this->expectException(DatabaseException::class);
        $this->db->insert('measurements', ['patient_id' => 999]);
    }

    public function testManagerResolvesRelativeSqlitePaths(): void
    {
        $base = sys_get_temp_dir() . '/zana-db-' . bin2hex(random_bytes(4));
        mkdir($base);
        $manager = new DatabaseManager(new Configuration(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => 'bmi.sqlite'], 'memory' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]), $base);

        $manager->connection()->execute('CREATE TABLE t (id INTEGER)');
        $this->assertFileExists("{$base}/bmi.sqlite");
        $this->assertSame($manager->connection(), $manager->connection('main'));
        $this->assertNotSame($manager->connection(), $manager->connection('memory'));

        try {
            $manager->connection('missing');
            $this->fail('An unknown connection must fail.');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('"missing" is not configured', $e->getMessage());
        }

        unset($manager);
        gc_collect_cycles();
        @unlink("{$base}/bmi.sqlite");
        @rmdir($base);
    }

    /**
     * 2.27.1: a fresh API skeleton has no var/, so its first migrate failed with "unable to open database file".
     */
    public function testTheFolderOfASqliteFileIsMadeWhenMissing(): void
    {
        $base = sys_get_temp_dir() . '/zana-db-' . bin2hex(random_bytes(4));
        mkdir($base);
        $manager = new DatabaseManager(new Configuration(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => 'var/data/app.sqlite']],
        ]]), $base);

        $manager->connection()->execute('CREATE TABLE t (id INTEGER)');
        $this->assertFileExists("{$base}/var/data/app.sqlite", 'both missing folders made, relative to the application');

        $pdo = ConnectionFactory::createPdo(['driver' => 'sqlite', 'database' => "{$base}/old/app.sqlite"]);
        $pdo->execute('CREATE TABLE t (id INTEGER)');
        $this->assertFileExists("{$base}/old/app.sqlite", 'the deprecated PDO connection too');

        // A file where the folder should be: refused, with the folder named, not "unable to open database file".
        file_put_contents("{$base}/taken", '');
        try {
            ConnectionFactory::create(['driver' => 'sqlite', 'database' => "{$base}/taken/app.sqlite"]);
            $this->fail('A folder that cannot be made must fail.');
        } catch (DatabaseException $e) {
            $this->assertSame("Cannot connect to the sqlite database: the folder {$base}/taken does not exist, and could not be made.", $e->getMessage());
        }

        try {
            ConnectionFactory::create(['driver' => 'sqlite', 'database' => "{$base}/nul\0x/app.sqlite"]);
            $this->fail('A NUL byte in the path must fail.');
        } catch (DatabaseException $e) {
            $this->assertSame('Cannot connect to the sqlite database: its path contains a NUL byte.', $e->getMessage(), 'not a ValueError from mkdir()');
        }

        unset($manager, $pdo);
        gc_collect_cycles();
        @unlink("{$base}/var/data/app.sqlite");
        @unlink("{$base}/old/app.sqlite");
        @unlink("{$base}/taken");
        @rmdir("{$base}/var/data");
        @rmdir("{$base}/var");
        @rmdir("{$base}/old");
        @rmdir($base);
    }

    public function testUnitOfWork(): void
    {
        $work = new TransactionalUnitOfWork($this->db);

        $work->begin();
        $this->db->insert('patients', ['name' => 'rolled back']);
        $work->rollback();

        $work->begin();
        $this->db->insert('patients', ['name' => 'kept']);
        $work->commit();

        $this->assertSame('result', $work->transactional(fn (): string => 'result'));

        try {
            $work->transactional(function (): void {
                $this->db->insert('patients', ['name' => 'failed']);
                throw new \RuntimeException('x');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([['name' => 'kept']], $this->db->select('SELECT name FROM patients'));
    }
}
