<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Database\Connections\PDOConnection;
use Zana\Database\Exceptions\DatabaseException;

class PDOConnectionTest extends TestCase
{
    private PDOConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new PDOConnection(
            'sqlite::memory:',
            '',
            '',
            []
        );
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testIsConnectedInitiallyFalse(): void
    {
        $conn = new PDOConnection('sqlite::memory:');
        $this->assertFalse($conn->isConnected());
    }

    public function testExecuteQuery(): void
    {
        $this->connection->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Bob']);

        $results = $this->connection->query('SELECT * FROM test ORDER BY id');
        $this->assertCount(2, $results);
        $this->assertEquals('Alice', $results[0]['name']);
        $this->assertEquals('Bob', $results[1]['name']);
    }

    public function testQueryReturnsEmptyArrayForNoResults(): void
    {
        $this->connection->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $results = $this->connection->query('SELECT * FROM test');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $this->connection->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Bob']);

        $affected = $this->connection->execute('UPDATE test SET name = ? WHERE name = ?', ['Alicia', 'Alice']);
        $this->assertEquals(1, $affected);
    }

    public function testLastInsertId(): void
    {
        $this->connection->execute('CREATE TABLE test (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Alice']);

        $id = $this->connection->lastInsertId();
        $this->assertEquals(1, $id);
    }

    public function testBeginTransactionCommit(): void
    {
        $this->connection->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');

        $this->connection->beginTransaction();
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $this->connection->commit();

        $results = $this->connection->query('SELECT * FROM test');
        $this->assertCount(1, $results);
    }

    public function testBeginTransactionRollback(): void
    {
        $this->connection->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');

        $this->connection->beginTransaction();
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $this->connection->rollback();

        $results = $this->connection->query('SELECT * FROM test');
        $this->assertEmpty($results);
    }

    public function testNestedTransactions(): void
    {
        $this->connection->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');

        $this->connection->beginTransaction();
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $this->connection->beginTransaction();
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Bob']);
        $this->connection->commit();
        $this->connection->commit();

        $results = $this->connection->query('SELECT * FROM test');
        $this->assertCount(2, $results);
    }

    public function testNestedTransactionRollbackOuter(): void
    {
        $this->connection->execute('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');

        $this->connection->beginTransaction();
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $this->connection->beginTransaction();
        $this->connection->execute('INSERT INTO test (name) VALUES (?)', ['Bob']);
        $this->connection->rollback(); // rollback inner
        $this->connection->rollback(); // rollback outer

        $results = $this->connection->query('SELECT * FROM test');
        $this->assertEmpty($results);
    }

    public function testRollbackWithoutTransactionReturnsFalse(): void
    {
        $result = $this->connection->rollback();
        $this->assertFalse($result);
    }

    public function testCommitWithoutTransactionReturnsFalse(): void
    {
        $result = $this->connection->commit();
        $this->assertFalse($result);
    }

    public function testIsConnectedAfterQuery(): void
    {
        $this->connection->execute('SELECT 1');
        $this->assertTrue($this->connection->isConnected());
    }

    public function testClose(): void
    {
        $this->connection->execute('SELECT 1');
        $this->connection->close();
        $this->assertFalse($this->connection->isConnected());
    }

    public function testFromConfigCreatesMySQLConnection(): void
    {
        $conn = PDOConnection::fromConfig([
            'driver' => 'sqlite',
            'host' => ':memory:',
            'database' => '',
        ]);

        $this->assertInstanceOf(PDOConnection::class, $conn);
        $conn->execute('SELECT 1');
        $this->assertTrue($conn->isConnected());
        $conn->close();
    }

    public function testQueryThrowsDatabaseException(): void
    {
        $this->expectException(DatabaseException::class);

        $this->connection->query('SELECT * FROM nonexistent_table');
    }

    public function testExecuteThrowsDatabaseException(): void
    {
        $this->expectException(DatabaseException::class);

        $this->connection->execute('INSERT INTO nonexistent (id) VALUES (?)', [1]);
    }
}
