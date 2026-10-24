<?php

declare(strict_types=1);

namespace Zana\Database\Grammar;

use Zana\Database\Schema\ColumnDefinition;

final class PostgresGrammar extends Grammar
{
    public function compileHasTable(string $table): array
    {
        return ['SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?', [$table]];
    }

    public function compileHasColumn(string $table, string $column): array
    {
        return ['SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$table, $column]];
    }

    protected function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function type(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'id' => 'BIGSERIAL PRIMARY KEY',
            'uuid' => 'UUID',
            'string' => "VARCHAR({$column->length})",
            'text' => 'TEXT',
            'json' => 'JSONB',
            'integer' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'boolean' => 'BOOLEAN',
            'decimal' => "NUMERIC({$column->precision}, {$column->scale})",
            'float' => 'DOUBLE PRECISION',
            'date' => 'DATE',
            'dateTime' => 'TIMESTAMP(0)',
            default => throw new \LogicException("Unknown column type {$column->type}."),
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }
}
