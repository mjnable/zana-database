# Zana Database

Database abstraction and migrations for the Zana framework.

## Features

- **`ConnectionInterface`** / **`PDOConnection`** — database-agnostic connection abstraction (MySQL, SQLite, PostgreSQL, SQL Server) with transactions and nested savepoints
- **`Migrator`** — discovers and runs migrations, rolls back batches, reports status
- **`MigrationRepository`** — tracks ran migrations with batch numbers
- **`Migration`** — abstract base migration class (`up()` / `down()`)
- **`DatabaseException`** / **`MigrationException`** — typed error handling
- **Console commands** — `migrate`, `migrate:rollback`, `migrate:status`, `migrate:make`

## Install

```
composer require zana/database
```

## Usage (Connection)

```php
use Zana\Database\Connections\PDOConnection;

$conn = PDOConnection::fromConfig([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'app',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
]);

$rows = $conn->query('SELECT * FROM users WHERE id = ?', [1]);
```

## Usage (Migrations)

```bash
php bin/console migrate
php bin/console migrate:status
php bin/console migrate:rollback
php bin/console migrate:make create_users_table
```

See `docs/MIGRATIONS.md` for full documentation.

## Testing

```
vendor/bin/phpunit packages/database/tests
```

## License

MIT — see [LICENSE](LICENSE).

