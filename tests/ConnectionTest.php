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
    private Connection $db;

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

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot connect to the sqlite database');
        ConnectionFactory::create(['driver' => 'sqlite', 'database' => '/definitely/missing/dir/db.sqlite']);
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
