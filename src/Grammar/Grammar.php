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

    /**
     * Statements for Schema::table(), in this order: drop indexes, drop
     * columns, add columns, create indexes. One change per statement, as SQLite
     * requires.
     *
     * @return list<string>
     *
     * @throws \LogicException For changes that cannot be applied to an existing table
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        if ($blueprint->declaredForeignKeys() !== []) {
            throw new \LogicException("Foreign keys cannot be added to the existing table {$blueprint->table}.");
        }

        $table = $this->wrap($blueprint->table);
        $statements = [];

        foreach ($blueprint->droppedIndexes() as $index) {
            $statements[] = $this->compileDropIndex($blueprint->table, $index);
        }
        foreach ($blueprint->droppedColumns() as $column) {
            $statements[] = "ALTER TABLE {$table} DROP COLUMN " . $this->quote($column);
        }

        $indexes = $blueprint->indexes();
        foreach ($blueprint->columns() as $column) {
            $where = "{$blueprint->table}.{$column->name}";
            if ($column->type === 'id' || $column->primary) {
                throw new \LogicException("A primary key cannot be added to the existing table {$blueprint->table} ({$where}).");
            }
            if (!$column->nullable && !$column->hasDefault) {
                throw new \LogicException("The new column {$where} must be nullable or have a default: existing rows need a value.");
            }

            // SQLite cannot add a UNIQUE column, so a unique column becomes a unique index everywhere.
            $definition = clone $column;
            if ($definition->unique) {
                $definition->unique = false;
                $indexes[] = ['unique', [$column->name], "{$blueprint->table}_{$column->name}_unique"];
            }
            $statements[] = "ALTER TABLE {$table} ADD COLUMN " . $this->column($definition);
        }

        foreach ($indexes as [$type, $columns, $name]) {
            $statements[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $type === 'unique' ? 'UNIQUE ' : '',
                $this->quote($name),
                $table,
                implode(', ', array_map($this->wrap(...), $columns))
            );
        }

        return $statements;
    }

    public function compileDropIndex(string $table, string $index): string
    {
        return 'DROP INDEX ' . $this->quote($index);
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
