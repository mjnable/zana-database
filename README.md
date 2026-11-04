# Zana Database

PDO connections, a query builder, a schema builder, module-aware migrations
and a transactional unit of work for SQLite, MySQL/MariaDB and PostgreSQL.
Requires PHP 8.5 or later.

```
composer require mj-zana/database
```

## Configuration

Register `DatabaseServiceProvider` (and `ConsoleServiceProvider` for the
commands), then configure `config/database.php`:

```php
return [
    'default' => 'main',
    'connections' => [
        'main' => ['driver' => 'sqlite', 'database' => 'var/bmi.sqlite'],
        'reporting' => ['driver' => 'mysql', 'host' => 'db', 'database' => 'bmi', 'username' => 'bmi', 'password' => '...'],
    ],
];
```

Relative SQLite paths are resolved against the application's base path.

## Queries

```php
public function __construct(private ConnectionInterface $db) {}

$this->db->table('measurements')
    ->select('patient_id', 'bmi')
    ->where('bmi', '>=', 25)
    ->whereIn('patient_id', $ids)
    ->orderBy('taken_on', 'desc')
    ->limit(20)
    ->get();

$this->db->table('measurements')->insert(['patient_id' => 7, 'bmi' => 22.5, 'taken_on' => '2026-10-24']);
$this->db->table('measurements')->where('id', 3)->update(['bmi' => 22.6]);

$this->db->transaction(fn (ConnectionInterface $db) => /* ... */);   // nested calls use savepoints
```

Values are always bound. `update()` and `delete()` without a `where` throw
unless you pass `force: true`. Query errors carry the SQL, never the bound
values.

## Schema and migrations

```php
// database/migrations/2026_10_24_120000_create_measurements_table.php
return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('measurements', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('patient_id');
            $t->decimal('bmi', 4, 1);
            $t->date('taken_on');
            $t->timestamps();
            $t->foreign('patient_id')->on('patients')->onDelete('cascade');
            $t->index(['patient_id', 'taken_on']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('measurements');
    }
};
```

```
php bin/zana make:migration create_measurements_table
php bin/zana migrate
php bin/zana migrate:status
php bin/zana migrate:rollback --step=1
```

Migrations come from `database/migrations` and from each module's
`Infrastructure/Persistence/Migrations`, in file-name order. A batch runs in
one transaction on SQLite and PostgreSQL. MySQL has no transactional DDL, so
a failure there leaves the earlier migrations applied.

## Unit of work

`UnitOfWorkInterface` (from `mj-zana/ddd`) resolves, per scope, to a
`TransactionalUnitOfWork` on the default connection.

## New in 2.12: on Doctrine DBAL

The connection runs on [Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html)
(ADR-027). The query and schema builders keep their API and their guard
rails; DBAL compiles them for the database, and `DbalConnection::dbal()`
gives its connection to integrations that must share it.

```php
// Reserve 3 seats only while sold + 3 <= capacity: one statement, safe under concurrency.
$won = $db->table('ticket_types')->where('id', $id)->whereColumn('sold', '<=', 'capacity', -3)->increment('sold', 3);

$db->table('rates')->upsert(['currency' => 'CDF', 'rate' => 2850], uniqueBy: 'currency');   // insert, or update rate
$row = $db->transaction(fn ($tx) => $tx->table('orders')->where('id', $id)->lockForUpdate()->first());
```

- `increment()` and `decrement()` change a column relative to itself, with
  other columns in the same statement; like `update()`, they need a where
  clause.
- `whereColumn($first, $operator, $second, $offset)` compares two columns.
- `upsert($rows, $uniqueBy, $update)`: `ON CONFLICT` on PostgreSQL and
  SQLite, `ON DUPLICATE KEY UPDATE` on MySQL, where any unique key of the
  table detects the conflict.
- `lockForUpdate()` adds `FOR UPDATE`; SQLite has no row locks.
- `DatabaseException::isRetryable()` is true for a deadlock or a lock wait
  timeout.
- **MySQL counts changed rows**, not matched ones: an update that writes the
  values a row already has returns 0.

`Connection` (PDO), its grammars and `ConnectionFactory::createPdo()` are
deprecated and go in 3.0.

## License

MIT — see [LICENSE](LICENSE).
