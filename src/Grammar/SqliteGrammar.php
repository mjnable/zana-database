<?php

declare(strict_types=1);

namespace Zana\Database\Grammar;

use Zana\Database\Schema\ColumnDefinition;

final class SqliteGrammar extends Grammar
{
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
