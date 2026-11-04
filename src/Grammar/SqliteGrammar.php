<?php

declare(strict_types=1);

namespace Zana\Database\Grammar;

use Zana\Database\Schema\ColumnDefinition;

/**
 * SQLite SQL for the PDO Connection.
 *
 * @deprecated since 2.12: DbalConnection uses PlatformGrammar (ADR-027); this class goes in 3.0.
 */
final class SqliteGrammar extends Grammar
{
    public function compileLockForUpdate(): string
    {
        return '';   // SQLite has no row locks; a write transaction holds the database
    }

    public function compileHasTable(string $table): array
    {
        return ["SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]];
    }

    public function compileHasColumn(string $table, string $column): array
    {
        return ['SELECT name FROM pragma_table_info(?) WHERE name = ?', [$table, $column]];
    }

    protected function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function type(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'uuid' => 'CHAR(36)',
            'string' => "VARCHAR({$column->length})",
            'text', 'json' => 'TEXT',
            'integer', 'bigInteger', 'boolean' => 'INTEGER',
            'decimal' => "NUMERIC({$column->precision}, {$column->scale})",
            'float' => 'REAL',
            'date' => 'DATE',
            'dateTime' => 'DATETIME',
            default => throw new \LogicException("Unknown column type {$column->type}."),
        };
    }
}
