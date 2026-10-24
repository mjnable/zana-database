<?php

declare(strict_types=1);

namespace Zana\Database\Grammar;

use Zana\Database\Schema\Blueprint;
use Zana\Database\Schema\ColumnDefinition;

/**
 * Driver-specific SQL: identifier quoting and DDL.
 */
abstract class Grammar
{
    /**
     * Quote an identifier: "table.column", "*" and "column as alias" are handled.
     */
    public function wrap(string $identifier): string
    {
        if (preg_match('/^(.+)\s+as\s+(.+)$/i', $identifier, $alias) === 1) {
            return $this->wrap(trim($alias[1])) . ' AS ' . $this->quote(trim($alias[2]));
        }

        return implode('.', array_map(
            fn (string $part): string => $part === '*' ? '*' : $this->quote($part),
            explode('.', $identifier)
        ));
    }

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * @return list<string> Statements creating the table and its indexes
     */
    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions = array_map(fn (ColumnDefinition $c): string => $this->column($c), $blueprint->columns());

        foreach ($blueprint->foreignKeys() as $foreign) {
            $definitions[] = sprintf(
                'FOREIGN KEY (%s) REFERENCES %s (%s)%s',
                $this->wrap($foreign->column),
                $this->wrap($foreign->table),
                $this->wrap($foreign->references),
                $foreign->onDelete === null ? '' : ' ON DELETE ' . strtoupper($foreign->onDelete)
            );
        }

        $statements = ['CREATE TABLE ' . $this->wrap($blueprint->table) . ' (' . implode(', ', $definitions) . ')' . $this->tableOptions()];

        foreach ($blueprint->indexes() as [$type, $columns, $name]) {
            $statements[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $type === 'unique' ? 'UNIQUE ' : '',
                $this->quote($name),
                $this->wrap($blueprint->table),
                implode(', ', array_map($this->wrap(...), $columns))
            );
        }

        return $statements;
    }

    public function compileDrop(string $table, bool $ifExists): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->wrap($table);
    }

    /**
     * @return array{string, list<string>} SQL and bindings returning a row when the table exists
     */
    abstract public function compileHasTable(string $table): array;

    /**
     * @return array{string, list<string>} SQL and bindings returning a row when the column exists
     */
    abstract public function compileHasColumn(string $table, string $column): array;

    abstract protected function quote(string $identifier): string;

    /**
     * Appended to CREATE TABLE, e.g. " ENGINE=InnoDB".
     */
    protected function tableOptions(): string
    {
        return '';
    }

    /**
     * The SQL type for a column, e.g. VARCHAR(255).
     */
    abstract protected function type(ColumnDefinition $column): string;

    protected function column(ColumnDefinition $column): string
    {
        if ($column->type === 'id') {
            return $this->quote($column->name) . ' ' . $this->type($column);
        }

        $sql = $this->quote($column->name) . ' ' . $this->type($column) . ($column->nullable ? ' NULL' : ' NOT NULL');

        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->literal($column->default);
        }
        if ($column->unique) {
            $sql .= ' UNIQUE';
        }
        if ($column->primary) {
            $sql .= ' PRIMARY KEY';
        }

        return $sql;
    }

    protected function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $this->booleanLiteral($value),
            is_int($value), is_float($value) => (string) $value,
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }
}
