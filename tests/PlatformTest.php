<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseException;
use Zana\Database\DbalConnection;
use Zana\Database\Grammar\PlatformGrammar;
use Zana\Database\Schema\Blueprint;

/**
 * ADR-027: the builders on Doctrine DBAL, on every database available here
 * (see Drivers): the column types, ALTER, the 2.12 builder methods, paging,
 * nested transactions, and a conditional reservation that concurrent buyers
 * cannot oversell.
 */
final class PlatformTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return Drivers::names();
    }

    #[DataProvider('drivers')]
    public function testEveryColumnTypeRoundTrips(string $driver): void
    {
        $db = $this->connect($driver);
        $this->assertInstanceOf(DbalConnection::class, $db);
        $this->assertInstanceOf(PlatformGrammar::class, $db->grammar());
        $this->assertSame($driver, $db->driver());

        $db->schema()->create('kinds', static function (Blueprint $t): void {
            $t->id();
            $t->uuid('ref')->unique();
            $t->string('name', 80);
            $t->text('notes')->nullable();
            $t->json('data')->nullable();
            $t->integer('seats')->default(0);
            $t->bigInteger('views')->default(0);
            $t->boolean('open')->default(true);
            $t->decimal('price', 8, 2);
            $t->float('ratio')->nullable();
            $t->date('day');
            $t->dateTime('at');
            $t->index(['name', 'day']);
        });
        $this->assertTrue($db->schema()->hasTable('kinds'));
        $this->assertTrue($db->schema()->hasColumn('kinds', 'price'));

        $db->table('kinds')->insert([
            'ref' => '01a19462-7acc-71b2-9deb-989f175ee2a8', 'name' => 'Concert', 'notes' => null, 'data' => '{"a":1}',
            'price' => '25.50', 'ratio' => 0.5, 'day' => '2026-12-31', 'at' => '2026-12-31 20:30:00',
        ]);
        $row = $db->table('kinds')->where('name', 'Concert')->first();
        $this->assertNotNull($row);
        // SQLite has no exact decimals: NUMERIC gives 25.5, the others '25.50'.
        $this->assertSame([25.5, 0, 1, '2026-12-31', '2026-12-31 20:30:00'], [(float) $row['price'], (int) $row['seats'], (int) $row['open'], (string) $row['day'], substr((string) $row['at'], 0, 19)]);
        $this->assertSame(['a' => 1], json_decode((string) $row['data'], true));

        try {
            $db->table('kinds')->insert(['ref' => '01a19462-7acc-71b2-9deb-989f175ee2a8', 'name' => 'Twice', 'price' => '1', 'day' => '2026-01-01', 'at' => '2026-01-01 00:00:00']);
            $this->fail('The unique column refuses a duplicate.');
        } catch (DatabaseException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    #[DataProvider('drivers')]
    public function testTablesAreAlteredInPlace(string $driver): void
    {
        $db = $this->connect($driver);
        $db->schema()->create('events', static function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->string('obsolete')->nullable();
            $t->index('title', 'events_title_index');
        });
        $db->table('events')->insert(['title' => 'Kinshasa Jazz', 'obsolete' => 'x']);

        $db->schema()->table('events', static function (Blueprint $t): void {
            $t->integer('capacity')->default(100);
            $t->string('slug', 120)->nullable()->unique();
            $t->dropColumn('obsolete');
            $t->dropIndex('events_title_index');
        });

        $this->assertFalse($db->schema()->hasColumn('events', 'obsolete'));
        $this->assertSame(['title' => 'Kinshasa Jazz', 'capacity' => 100, 'slug' => null], self::subset($db->table('events')->first(), ['title', 'capacity', 'slug']), 'existing rows keep their data and get the default');
        $db->table('events')->where('title', 'Kinshasa Jazz')->update(['slug' => 'jazz']);
        $this->expectException(DatabaseException::class);
        $db->table('events')->insert(['title' => 'Copy', 'slug' => 'jazz']);
    }

    #[DataProvider('drivers')]
    public function testCountersPagesLocksAndUpserts(string $driver): void
    {
        $db = $this->connect($driver);
        $db->schema()->create('counters', static function (Blueprint $t): void {
            $t->id();
            $t->string('code', 20)->unique();
            $t->integer('sold')->default(0);
            $t->integer('capacity');
        });
        $db->table('counters')->insert([['code' => 'A', 'capacity' => 3], ['code' => 'B', 'capacity' => 1], ['code' => 'C', 'capacity' => 5]]);

        // A reservation of 2 seats succeeds while sold + 2 <= capacity, as one statement.
        $reserve = static fn (string $code, int $seats): int => $db->table('counters')->where('code', $code)->whereColumn('sold', '<=', 'capacity', -$seats)->increment('sold', $seats);
        $this->assertSame([1, 0], [$reserve('A', 2), $reserve('A', 2)], 'the second would oversell');
        $this->assertSame(1, $db->table('counters')->where('code', 'A')->decrement('sold', 1, ['capacity' => 4]));
        $this->assertSame(['sold' => 1, 'capacity' => 4], self::subset($db->table('counters')->where('code', 'A')->first(), ['sold', 'capacity']));

        $this->assertSame(['B', 'C'], array_column($db->table('counters')->orderBy('code')->limit(2)->offset(1)->get(), 'code'));
        $this->assertSame(['C'], array_column($db->table('counters')->orderBy('code')->offset(2)->get(), 'code'));

        $locked = $db->transaction(static fn (ConnectionInterface $tx): ?array => $tx->table('counters')->where('code', 'C')->lockForUpdate()->first());
        $this->assertSame('C', $locked['code'] ?? null);

        $db->table('counters')->upsert([['code' => 'C', 'sold' => 0, 'capacity' => 9], ['code' => 'D', 'sold' => 0, 'capacity' => 2]], 'code', ['capacity']);
        $db->table('counters')->upsert(['code' => 'D', 'sold' => 0, 'capacity' => 99], 'code', []);
        $this->assertSame(['C' => 9, 'D' => 2], array_map('intval', array_column($db->table('counters')->whereIn('code', ['C', 'D'])->orderBy('code')->get(), 'capacity', 'code')), 'conflicts update the listed columns, or nothing');
        $this->assertSame(4, $db->table('counters')->count());

        $this->expectException(DatabaseException::class);
        $db->table('counters')->increment('sold');   // no where clause: refused like update()
    }

    #[DataProvider('drivers')]
    public function testNestedTransactionsUseSavepoints(string $driver): void
    {
        $db = $this->connect($driver);
        $db->schema()->create('notes', static fn (Blueprint $t) => $t->string('text'));

        $db->transaction(function (ConnectionInterface $tx): void {
            $tx->table('notes')->insert(['text' => 'kept']);
            try {
                $tx->transaction(static function (ConnectionInterface $inner): void {
                    $inner->table('notes')->insert(['text' => 'rolled back']);
                    throw new \RuntimeException('inner failure');
                });
            } catch (\RuntimeException) {
            }
            $this->assertSame(1, $tx->transactionLevel());
        });

        $this->assertSame(['kept'], array_column($db->table('notes')->get(), 'text'));
        $this->assertSame(0, $db->transactionLevel());
        $this->expectExceptionMessage('Cannot commit: no transaction is active.');
        $db->commit();
    }

    #[DataProvider('drivers')]
    public function testConcurrentBuyersNeverOversell(string $driver): void
    {
        $file = $driver === 'sqlite' ? str_replace('\\', '/', sys_get_temp_dir()) . '/zana-database-race-' . bin2hex(random_bytes(4)) . '.sqlite' : null;
        $settings = $this->settings($driver, $file);
        $db = ConnectionFactory::create($settings);
        $db->schema()->dropIfExists('counters');
        $db->schema()->create('counters', static function (Blueprint $t): void {
            $t->id();
            $t->integer('sold')->default(0);
            $t->integer('capacity');
        });
        $db->table('counters')->insert(['capacity' => 25]);

        // Four buyer processes try 15 reservations each, at the same time, for 25 seats. They get the
        // driver's extension when this run loaded it on the command line (php -d extension=pdo_pgsql).
        $php = [PHP_BINARY, '-d', 'xdebug.mode=off'];
        exec(escapeshellarg(PHP_BINARY) . " -r \"echo extension_loaded('pdo_{$driver}') ? 1 : 0;\"", $loaded);
        if ($driver !== 'sqlite' && ($loaded[0] ?? '1') === '0') {
            array_push($php, '-d', "extension=pdo_{$driver}");
        }
        $workers = [];
        foreach (range(1, 4) as $_) {
            $worker = proc_open([...$php, __DIR__ . '/reserve-worker.php', json_encode($settings, JSON_THROW_ON_ERROR), '15'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[]);
            $this->assertIsResource($worker, 'a buyer process starts');
            $workers[] = $worker;
        }
        $won = 0;
        foreach ($workers as $i => $worker) {
            $output = (string) stream_get_contents($pipes[$i][1]);
            $errors = (string) stream_get_contents($pipes[$i][2]);
            $this->assertSame(0, proc_close($worker), "{$output}\n{$errors}");
            $won += (int) $output;
        }

        $this->assertSame(25, $won, 'every seat is sold once');
        $this->assertSame(25, (int) $db->table('counters')->where('id', 1)->value('sold'), 'and never more');
        if ($file !== null) {
            unset($db);
            @unlink($file);
        }
    }

    private function connect(string $driver): ConnectionInterface
    {
        $db = ConnectionFactory::create($this->settings($driver));
        foreach (['kinds', 'events', 'counters', 'notes'] as $table) {
            $db->schema()->dropIfExists($table);
        }

        return $db;
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

    /**
     * @param array<string, mixed>|null $row
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function subset(?array $row, array $keys): array
    {
        $subset = [];
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            $subset[$key] = is_numeric($value) && !is_string($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $value;
        }

        return $subset;
    }
}
