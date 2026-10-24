<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Database\Connection;
use Zana\Database\ConnectionFactory;
use Zana\Database\DatabaseException;
use Zana\Database\Grammar\MysqlGrammar;
use Zana\Database\Grammar\PostgresGrammar;

final class QueryBuilderTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = ConnectionFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->execute('CREATE TABLE patients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER, city TEXT)');
        $this->db->execute('CREATE TABLE measurements (id INTEGER PRIMARY KEY AUTOINCREMENT, patient_id INTEGER, bmi REAL)');
        $this->db->table('patients')->insert([
            ['name' => 'Ada', 'age' => 36, 'city' => 'London'],
            ['name' => 'Grace', 'age' => 45, 'city' => 'New York'],
            ['name' => 'Alan', 'age' => 41, 'city' => 'London'],
            ['name' => 'Edsger', 'age' => 72, 'city' => null],
        ]);
        $this->db->table('measurements')->insert([['patient_id' => 1, 'bmi' => 22.5], ['patient_id' => 1, 'bmi' => 23.0], ['patient_id' => 2, 'bmi' => 25.1]]);
    }

    public function testFilteringOrderingAndPaging(): void
    {
        $names = $this->db->table('patients')
            ->select('name')
            ->where('age', '>', 30)
            ->where('city', 'London')
            ->orderBy('age', 'desc')
            ->pluck('name');

        $this->assertSame(['Alan', 'Ada'], $names);
        $this->assertSame(['Alan', 'Grace'], $this->db->table('patients')->orderBy('age')->limit(2)->offset(1)->pluck('name'));
        $this->assertSame(['Edsger'], $this->db->table('patients')->offset(3)->pluck('name'), 'offset without limit');
    }

    public function testOrInNullAndLike(): void
    {
        $this->assertSame(['Ada', 'Edsger'], $this->db->table('patients')->where('age', '<', 40)->orWhere('age', '>', 70)->orderBy('id')->pluck('name'));
        $this->assertSame(['Ada', 'Grace'], $this->db->table('patients')->whereIn('id', [1, 2])->pluck('name'));
        $this->assertSame(['Alan', 'Edsger'], $this->db->table('patients')->whereNotIn('id', [1, 2])->pluck('name'));
        $this->assertSame([], $this->db->table('patients')->whereIn('id', [])->get());
        $this->assertCount(4, $this->db->table('patients')->whereNotIn('id', [])->get());
        $this->assertSame(['Edsger'], $this->db->table('patients')->whereNull('city')->pluck('name'));
        $this->assertSame(['Edsger'], $this->db->table('patients')->where('city', null)->pluck('name'), 'where = null becomes IS NULL');
        $this->assertCount(3, $this->db->table('patients')->whereNotNull('city')->get());
        $this->assertSame(['Ada', 'Alan'], $this->db->table('patients')->where('name', 'like', 'A%')->orderBy('name')->pluck('name'));
    }

    public function testAggregatesAndSingleValues(): void
    {
        $this->assertSame(4, $this->db->table('patients')->count());
        $this->assertSame(2, $this->db->table('patients')->where('city', 'London')->count());
        $this->assertTrue($this->db->table('patients')->where('name', 'Ada')->exists());
        $this->assertFalse($this->db->table('patients')->where('name', 'Nobody')->exists());
        $this->assertSame(45, $this->db->table('patients')->where('name', 'Grace')->value('age'));
        $this->assertNull($this->db->table('patients')->where('name', 'Nobody')->value('age'));
        $this->assertSame(['id' => 1, 'name' => 'Ada', 'age' => 36, 'city' => 'London'], $this->db->table('patients')->orderBy('id')->first());

        $cities = $this->db->table('patients')->select('city')->distinct()->whereNotNull('city')->orderBy('city')->pluck('city');
        $this->assertSame(['London', 'New York'], $cities, 'distinct removes the duplicate London');
    }

    public function testJoinsAndGrouping(): void
    {
        $rows = $this->db->table('patients')
            ->select('patients.name', 'measurements.bmi as bmi')
            ->join('measurements', 'measurements.patient_id', '=', 'patients.id')
            ->where('patients.name', 'Ada')
            ->orderBy('bmi')
            ->get();

        $this->assertSame([['name' => 'Ada', 'bmi' => 22.5], ['name' => 'Ada', 'bmi' => 23.0]], $rows);
        $this->assertCount(5, $this->db->table('patients')->leftJoin('measurements', 'measurements.patient_id', '=', 'patients.id')->get());

        $grouped = $this->db->table('measurements')->select('patient_id')->groupBy('patient_id')->orderBy('patient_id')->pluck('patient_id');
        $this->assertSame([1, 2], $grouped);
    }

    public function testUpdateAndDeleteRequireAFilter(): void
    {
        $this->assertSame(2, $this->db->table('patients')->where('city', 'London')->update(['city' => 'Cambridge']));
        $this->assertSame(1, $this->db->table('patients')->where('name', 'Edsger')->delete());

        foreach (['update' => fn () => $this->db->table('patients')->update(['age' => 0]), 'delete' => fn () => $this->db->table('patients')->delete()] as $action => $call) {
            try {
                $call();
                $this->fail("An unfiltered {$action} must be refused.");
            } catch (DatabaseException $e) {
                $this->assertStringContainsString("Refusing to {$action} every row", $e->getMessage());
            }
        }

        $this->assertSame(3, $this->db->table('patients')->update(['age' => 1], force: true));
        $this->assertSame(3, $this->db->table('patients')->delete(force: true));
    }

    public function testInvalidInput(): void
    {
        foreach ([
            fn () => $this->db->table('patients')->where('age', 'BETWEEN', 1),
            fn () => $this->db->table('patients')->orderBy('age', 'side' . 'ways'),
            fn () => $this->db->table('patients')->join('m', 'a', '=', 'b', 'CROSS'),
            fn () => $this->db->table('patients')->insert([['name' => 'a'], ['age' => 1]]),
        ] as $call) {
            try {
                $call();
                $this->fail('Invalid input must be rejected.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->db->table('patients')->insert([]));
    }

    public function testSqlPerGrammar(): void
    {
        $query = fn ($db) => $db->table('patients')
            ->select('patients.name', 'm.bmi as bmi')
            ->join('measurements as m', 'm.patient_id', '=', 'patients.id')
            ->where('age', '>=', 18)
            ->whereIn('city', ['London', 'Paris'])
            ->orderBy('name')
            ->limit(10)
            ->toSql();

        $this->assertSame(
            ['SELECT "patients"."name", "m"."bmi" AS "bmi" FROM "patients" INNER JOIN "measurements" AS "m" ON "m"."patient_id" = "patients"."id" WHERE "age" >= ? AND "city" IN (?, ?) ORDER BY "name" ASC LIMIT 10', [18, 'London', 'Paris']],
            $query($this->db)
        );
        $this->assertSame(
            'SELECT `patients`.`name`, `m`.`bmi` AS `bmi` FROM `patients` INNER JOIN `measurements` AS `m` ON `m`.`patient_id` = `patients`.`id` WHERE `age` >= ? AND `city` IN (?, ?) ORDER BY `name` ASC LIMIT 10',
            $query(new GrammarConnection($this->db, new MysqlGrammar()))[0]
        );
        $this->assertStringStartsWith('SELECT "patients"."name"', $query(new GrammarConnection($this->db, new PostgresGrammar()))[0]);
    }

    public function testIdentifiersAreQuotedSafely(): void
    {
        [$sql] = $this->db->table('weird"table')->select('col"umn')->toSql();
        [$mysql] = (new GrammarConnection($this->db, new MysqlGrammar()))->table('weird`table')->toSql();

        $this->assertSame('SELECT "col""umn" FROM "weird""table"', $sql);
        $this->assertSame('SELECT * FROM `weird``table`', $mysql);
    }
}
