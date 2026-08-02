<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Database\Connections\PDOConnection;
use App\Modules\BMI\Infrastructure\Persistence\MysqlPersonRepository;
use App\Modules\BMI\Domain\Entities\Person;
use App\Modules\BMI\Domain\Entities\Measurement;
use App\Modules\BMI\Domain\ValueObjects\Height;
use App\Modules\BMI\Domain\ValueObjects\Weight;

class MysqlPersonRepositoryTest extends TestCase
{
    private PDOConnection $connection;
    private MysqlPersonRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PDOConnection('sqlite::memory:', '', '', []);
        $this->repository = new MysqlPersonRepository($this->connection);

        // Create schema (SQLite-compatible)
        $this->connection->execute('
            CREATE TABLE IF NOT EXISTS persons (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');

        $this->connection->execute('
            CREATE TABLE IF NOT EXISTS measurements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                person_id INTEGER NOT NULL,
                height REAL NOT NULL,
                weight REAL NOT NULL,
                bmi REAL NOT NULL,
                bmi_category TEXT NOT NULL,
                recorded_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
            )
        ');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS measurements');
        $this->connection->execute('DROP TABLE IF EXISTS persons');
        $this->connection->close();
    }

    public function testSaveAndFindPerson(): void
    {
        $person = new Person('Alice', 'alice@example.com');
        $this->repository->save($person);

        $this->assertNotNull($person->getId());

        $found = $this->repository->find($person->getId());
        $this->assertNotNull($found);
        $this->assertEquals('Alice', $found->getName());
        $this->assertEquals('alice@example.com', $found->getEmail());
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        $found = $this->repository->find(999);
        $this->assertNull($found);
    }

    public function testExists(): void
    {
        $person = new Person('Bob');
        $this->repository->save($person);

        $this->assertTrue($this->repository->exists($person->getId()));
        $this->assertFalse($this->repository->exists(999));
    }

    public function testFindAll(): void
    {
        $this->repository->save(new Person('Alice'));
        $this->repository->save(new Person('Bob'));

        $all = $this->repository->findAll();
        $this->assertCount(2, $all);
    }

    public function testDeletePerson(): void
    {
        $person = new Person('Charlie');
        $this->repository->save($person);
        $this->assertTrue($this->repository->exists($person->getId()));

        $this->repository->delete($person);
        $this->assertFalse($this->repository->exists($person->getId()));
    }

    public function testSaveWithMeasurements(): void
    {
        $person = new Person('Diana');
        $height = new Height(1.65);
        $weight = new Weight(60.0);
        $measurement = new Measurement($height, $weight);

        $person->recordMeasurement($measurement);
        $this->repository->save($person);

        $found = $this->repository->find($person->getId());
        $this->assertNotNull($found);
        $this->assertCount(1, $found->getMeasurements());

        $bmi = $found->getLatestBMI();
        $this->assertNotNull($bmi);
        $this->assertEquals(22.04, $bmi->getValue());
    }

    public function testUpdatePerson(): void
    {
        $person = new Person('Eve', 'eve@example.com');
        $this->repository->save($person);

        // Update name using reflection
        $ref = new \ReflectionClass($person);
        $nameProp = $ref->getProperty('name');
        $nameProp->setAccessible(true);
        $nameProp->setValue($person, 'Eve Updated');

        $this->repository->save($person);

        $found = $this->repository->find($person->getId());
        $this->assertNotNull($found);
        $this->assertEquals('Eve Updated', $found->getName());
    }

    public function testPersonWithNullEmail(): void
    {
        $person = new Person('Frank');
        $this->repository->save($person);

        $found = $this->repository->find($person->getId());
        $this->assertNull($found->getEmail());
    }

    public function testMultipleMeasurements(): void
    {
        $person = new Person('Grace');
        $this->repository->save($person);

        $m1 = new Measurement(new Height(1.70), new Weight(65.0));
        $m2 = new Measurement(new Height(1.70), new Weight(68.0));
        $person->recordMeasurement($m1);
        $person->recordMeasurement($m2);
        $this->repository->save($person);

        $found = $this->repository->find($person->getId());
        $this->assertCount(2, $found->getMeasurements());
    }

    public function testDeletePersonCascadesMeasurements(): void
    {
        $person = new Person('Hank');
        $person->recordMeasurement(new Measurement(new Height(1.80), new Weight(80.0)));
        $this->repository->save($person);

        $this->repository->delete($person);

        $this->assertNull($this->repository->find($person->getId()));
    }
}
