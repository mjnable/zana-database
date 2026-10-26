# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

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
