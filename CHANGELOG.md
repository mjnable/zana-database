# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.20.0 — Unreleased

### Security

- `whereLike($column, $text, LikeMatch::StartsWith|Contains|EndsWith)` and `orWhereLike()` search text where the
  user's `%`, `_` and `!` are plain characters, with `ESCAPE '!'` on every database (FE-75, S31). With
  `where('email', 'like', $input . '%')`, a user's `%` matched anything, and escaping by hand differed per database:
  `addcslashes()` worked on PostgreSQL and never matched `_` on SQLite. `!` is the escape character because a
  backslash is itself an escape in MySQL string literals. Case follows the database.
- A value bound to a query must be a string, a number, a bool, null or a resource; a `Stringable` binds as its
  string (S33). An array was bound as the text `Array` with only a warning, so a form field sent as `email[]=x`
  stored `"Array"`, and `where('token', $value)` compared with it. An array or another object now throws a
  `DatabaseException` that names its type, never its value, on both connections and in raw SQL.
- `orderByAllowed($requested, $allowed, default:, direction:)` and `selectAllowed($requested, $allowed)` take a
  sort or a field list from a request (S29). `orderBy()` and `select()` accept any real column, so `?sort=reset_token`
  sorted by a secret. Only allowed names count, and a map gives each name its column (`'newest' => 'created_at'`).
  An unknown name, an array or a bad direction falls back to the default and never causes an error; a default that
  is not in the list is a mistake in code and throws `InvalidArgumentException`.
- On SQLite, identifiers are quoted with backticks. SQLite read a double-quoted name that matched no column as a
  string, so `where('nope', 'nope')` matched every row and `value('reason')` returned `'reason'`; an unknown column now
  fails, as on PostgreSQL and MySQL (FE-72).
- TLS settings for connections (FE-78): `sslmode`, `sslrootcert`, `sslcert`, `sslkey` and `sslcrl` for PostgreSQL;
  `ssl_ca`, `ssl_cert`, `ssl_key` and `ssl_verify` for MySQL. An unknown `sslmode` is refused. libpq's `PGSSLMODE`
  in `.env` never reached the driver on Windows, and the connection stayed unencrypted.
  Certificate paths are quoted for libpq, so Windows paths keep their backslashes and paths with spaces work.
- `check` reports `database.tls` when a connection to another host is not encrypted, as the server itself reports it:
  an error in production, a warning elsewhere.

### Added

- `$t->check('name', 'expression')` declares a CHECK constraint, named `{table}_{name}_check`, on PostgreSQL, MySQL
  and SQLite (FE-74). In `table()` it is added with `ALTER TABLE … ADD CONSTRAINT`, and refused on SQLite, which
  cannot add a constraint to an existing table.
- `$t->smallInteger()`: `SMALLINT` on PostgreSQL and MySQL, `INTEGER` on SQLite (FE-74).
- The `uuid()` docblock and the database guide say to store canonical UUIDs: PostgreSQL returns 32 hexadecimal
  digits hyphenated, so such an id is not found by its own value there.
- `where(Closure)` and `orWhere(Closure)` group conditions in parentheses: `->where('city', 'London')->where(fn
  ($q) => $q->where('age', '<', 18)->orWhere('age', '>', 65))` (FE-75). Groups nest; an empty group adds nothing.
  `where('age')` with no value now throws `ArgumentCountError` with a message, as before but clearer.

### Changed

- On SQLite, `table()` refuses a change that makes DBAL rebuild the table (adding an index, dropping a column) when
  the table has CHECK constraints or triggers: the rebuilt table kept only the columns and indexes, and the
  constraints were dropped without a word. Adding a nullable column needs no rebuild and still works.
- `scalar()` returns `false` for a false value and `null` only when there is no row or the value is SQL `NULL`
  (FE-79). Before, `scalar('SELECT false')` returned `null` on PostgreSQL, the same as no row, so `=== false` never
  matched. SQLite and MySQL return `0` and `1`, as before. Code that read a PostgreSQL boolean with `=== null` for
  "no" must now test `=== false` or `!$value`.

## 2.12.0 — 2026-11-04

### Added

- `DbalConnection`: the connection on Doctrine DBAL (`doctrine/dbal` ^4.4), which
  `ConnectionFactory::create()` and `DatabaseServiceProvider` now return; `dbal()` gives the DBAL
  connection to integrations that must share it (FE-60, ADR-027).
- `PlatformGrammar`: DBAL's platform and schema tools behind `Grammar`, so the query and schema builders
  keep their API and guard rails. `ALTER` compares the introspected table with the changed one, and DBAL
  rebuilds SQLite tables where needed.
- Builder methods: `increment()`, `decrement()`, `whereColumn()` (with an offset, for
  `sold <= capacity - 3`), `lockForUpdate()` and `upsert()`.
- `DatabaseException::isRetryable()` for deadlocks and lock wait timeouts.
- Platform tests on SQLite, and on MySQL and PostgreSQL when they answer (`ZANA_TEST_MYSQL_*`,
  `ZANA_TEST_PGSQL_*`), including concurrent buyers who never oversell one counter row.

### Changed

- `ConnectionFactory::create()` is declared to return `ConnectionInterface` (a `DbalConnection`).
- MySQL counts changed rows, not matched rows, in the affected row count.
- New PostgreSQL `id()` columns are `GENERATED BY DEFAULT AS IDENTITY` rather than `BIGSERIAL`; both
  auto-increment, and existing tables are unchanged. The other column types are those of 2.11.

### Deprecated

- `Connection` (PDO), `MysqlGrammar`, `PostgresGrammar`, `SqliteGrammar` and the new
  `ConnectionFactory::createPdo()`, which keeps the 2.11 connection; all go in 3.0.

## 2.11.0 — 2026-11-01

### Changed

- The pending-migrations hint names the application's console script, e.g. `php bin/zana migrate`
  (FE-54).
- Requires `mj-zana/core` ^2.11.

## 2.7.0 — 2026-10-31

### Added

- A `migrations` section for `inspect`, and a check for pending migrations and applied migrations
  whose file is missing. The check connects only when it runs.

### Changed

- Requires `mj-zana/core` ^2.7.

## 2.4.0 — 2026-10-26

### Changed

- Factory registrations declare the services they resolve with `uses()`, so the container checks
  their lifetimes at startup (FE-40).
- Requires `mj-zana/core` ^2.4.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-beta.2 — 2026-10-25

### Added

- `Schema::table()` alters existing tables: add and drop columns, indexes and foreign keys.

## 2.0.0-beta.1 — 2026-10-25

### Fixed

- MySQL tables are created as InnoDB with utf8mb4. A server defaulting to MyISAM had no transactions
  and rejected long index keys.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. PDO connections, a query builder, a schema builder, module-aware migrations and a transactional unit of work for SQLite, MySQL/MariaDB and PostgreSQL. Requires PHP 8.5 or later.

Documentation: [the database guide](https://github.com/mjnable/zana-docs/blob/main/guides/database.md).
