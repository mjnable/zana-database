<?php

declare(strict_types=1);

namespace Zana\Database\Grammar;

use Zana\Database\Schema\Blueprint;
use Zana\Database\Schema\ColumnDefinition;

/**
 * Driver-specific SQL: identifier quoting, limits, row locks, upserts and DDL.
 * The connection on DBAL uses PlatformGrammar (ADR-027); the MySQL, PostgreSQL
 * and SQLite grammars serve the deprecated PDO Connection.
 */
abstract class Grammar
{
    /**
     * Add LIMIT and OFFSET to a SELECT.
     */
    public function compileLimit(string $sql, ?int $limit, ?int $offset): string
    {
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        if ($offset !== null) {
            $sql .= ($limit === null ? ' LIMIT ' . PHP_INT_MAX : '') . ' OFFSET ' . $offset;
        }

        return $sql;
    }

    /**
     * What locks the selected rows until the transaction ends (2.12); empty where the database
     * serializes writers itself (SQLite).
     */
    public function compileLockForUpdate(): string
    {
        return ' FOR UPDATE';
    }

    /**
     * INSERT … rows, or update the conflicting ones (2.12): ON CONFLICT … DO UPDATE by default.
     *
     * @param list<string> $columns
     * @param list<string> $uniqueBy The columns of the unique key that detects a conflict
     * @param list<string> $update The columns to update on a conflict; none does nothing
     */
    public function compileUpsert(string $table, array $columns, int $rows, array $uniqueBy, array $update): string
    {
        $sql = $this->compileInsertRows($table, $columns, $rows) . ' ON CONFLICT (' . implode(', ', array_map($this->wrap(...), $uniqueBy)) . ')';
        if ($update === []) {
            return $sql . ' DO NOTHING';
        }

        return $sql . ' DO UPDATE SET ' . implode(', ', array_map(fn (string $column): string => $this->wrap($column) . ' = excluded.' . $this->wrap($column), $update));
    }

    /**
     * MySQL and MariaDB: ON DUPLICATE KEY UPDATE, on any unique key of the table. With nothing to update, the
     * first unique column is set to itself, so a conflict changes nothing (INSERT IGNORE would hide other errors).
     *
     * @param list<string> $columns
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    final protected function compileOnDuplicateKeyUpsert(string $table, array $columns, int $rows, array $uniqueBy, array $update): string
    {
        $update = $update === [] ? [$uniqueBy[0]] : $update;

        return $this->compileInsertRows($table, $columns, $rows) . ' ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(fn (string $column): string => $this->wrap($column) . ' = VALUES(' . $this->wrap($column) . ')', $update));
    }

    /**
     * @param list<string> $columns
     */
    public function compileInsertRows(string $table, array $columns, int $rows): string
    {
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return sprintf('INSERT INTO %s (%s) VALUES %s', $this->wrap($table), implode(', ', array_map($this->wrap(...), $columns)), implode(', ', array_fill(0, $rows, $tuple)));
    }

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
