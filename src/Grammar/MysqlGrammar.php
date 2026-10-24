<?php

declare(strict_types=1);

namespace Zana\Database\Grammar;

use Zana\Database\Schema\ColumnDefinition;

final class MysqlGrammar extends Grammar
{
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    public function compileHasTable(string $table): array
    {
        return ['SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]];
    }

    public function compileHasColumn(string $table, string $column): array
    {
        return ['SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]];
    }

    protected function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function type(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'uuid' => 'CHAR(36)',
            'string' => "VARCHAR({$column->length})",
            'text' => 'TEXT',
            'json' => 'JSON',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            'decimal' => "DECIMAL({$column->precision}, {$column->scale})",
            'float' => 'DOUBLE',
            'date' => 'DATE',
            'dateTime' => 'DATETIME',
            default => throw new \LogicException("Unknown column type {$column->type}."),
        };
    }
}
