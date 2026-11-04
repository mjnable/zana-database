<?php

declare(strict_types=1);

namespace Zana\Database\Grammar;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Zana\Database\Schema\Blueprint;
use Zana\Database\Schema\ColumnDefinition;

/**
 * The grammar of DbalConnection (ADR-027): quoting, limits, row locks and
 * upserts from DBAL's platform; CREATE from DBAL schema objects; ALTER by
 * comparing the introspected table with the changed one, which lets DBAL
 * rebuild SQLite tables where needed. The column types are those of the
 * hand-written grammars (PostgreSQL JSONB and UUID, MySQL InnoDB utf8mb4),
 * so existing tables need no migration.
 */
final class PlatformGrammar extends Grammar
{
    private readonly AbstractPlatform $platform;

    public function __construct(private readonly Connection $connection)
    {
        $this->platform = $connection->getDatabasePlatform();
    }

    public function supportsTransactionalDdl(): bool
    {
        return !$this->platform instanceof AbstractMySQLPlatform;
    }

    public function compileLimit(string $sql, ?int $limit, ?int $offset): string
    {
        return $limit === null && $offset === null ? $sql : $this->platform->modifyLimitQuery($sql, $limit, $offset ?? 0);
    }

    public function compileLockForUpdate(): string
    {
        return $this->platform instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    public function compileUpsert(string $table, array $columns, int $rows, array $uniqueBy, array $update): string
    {
        return $this->platform instanceof AbstractMySQLPlatform
            ? $this->compileOnDuplicateKeyUpsert($table, $columns, $rows, $uniqueBy, $update)
            : parent::compileUpsert($table, $columns, $rows, $uniqueBy, $update);
    }

    public function compileCreate(Blueprint $blueprint): array
    {
        $table = new Table($this->quote($blueprint->table));
        foreach ($blueprint->columns() as $column) {
            $this->addColumn($table, $column, $blueprint->table);
        }
        foreach ($blueprint->foreignKeys() as $foreign) {
            $table->addForeignKeyConstraint(
                $this->quote($foreign->table),
                [$this->quote($foreign->column)],
                [$this->quote($foreign->references)],
                $foreign->onDelete === null ? [] : ['onDelete' => strtoupper($foreign->onDelete)],
            );
        }
        $this->addIndexes($table, $blueprint->indexes());
        if ($this->platform instanceof AbstractMySQLPlatform) {
            $table->addOption('engine', 'InnoDB')->addOption('charset', 'utf8mb4')->addOption('collation', 'utf8mb4_unicode_ci');
        }

        return $this->platform->getCreateTableSQL($table);
    }

    public function compileAlter(Blueprint $blueprint): array
    {
        if ($blueprint->declaredForeignKeys() !== []) {
            throw new \LogicException("Foreign keys cannot be added to the existing table {$blueprint->table}.");
        }
        if ($blueprint->table === '') {
            throw new \LogicException('The table to alter needs a name.');
        }
        $manager = $this->connection->createSchemaManager();
        $old = $manager->introspectTableByUnquotedName($blueprint->table);
        $new = clone $old;

        foreach ($blueprint->droppedIndexes() as $index) {
            $new->dropIndex($index);
        }
        foreach ($blueprint->droppedColumns() as $column) {
            $new->dropColumn($column);
        }
        foreach ($blueprint->columns() as $column) {
            $where = "{$blueprint->table}.{$column->name}";
            if ($column->type === 'id' || $column->primary) {
                throw new \LogicException("A primary key cannot be added to the existing table {$blueprint->table} ({$where}).");
            }
            if (!$column->nullable && !$column->hasDefault) {
                throw new \LogicException("The new column {$where} must be nullable or have a default: existing rows need a value.");
            }
            $this->addColumn($new, $column, $blueprint->table);
        }
        $this->addIndexes($new, $blueprint->indexes());

        return $this->platform->getAlterTableSQL($manager->createComparator()->compareTables($old, $new));
    }

    public function compileDrop(string $table, bool $ifExists): string
    {
        return $ifExists ? 'DROP TABLE IF EXISTS ' . $this->wrap($table) : $this->platform->getDropTableSQL($this->wrap($table));
    }

    public function compileHasTable(string $table): array
    {
        return match (true) {
            $this->platform instanceof SQLitePlatform => ["SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]],
            $this->platform instanceof AbstractMySQLPlatform => ['SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]],
            default => ['SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?', [$table]],
        };
    }

    public function compileHasColumn(string $table, string $column): array
    {
        return match (true) {
            $this->platform instanceof SQLitePlatform => ['SELECT name FROM pragma_table_info(?) WHERE name = ?', [$table, $column]],
            $this->platform instanceof AbstractMySQLPlatform => ['SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]],
            default => ['SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$table, $column]],
        };
    }

    protected function quote(string $identifier): string
    {
        return $this->platform->quoteSingleIdentifier($identifier);
    }

    protected function type(ColumnDefinition $column): string
    {
        throw new \LogicException('PlatformGrammar declares columns through DBAL.');
    }

    private function addColumn(Table $table, ColumnDefinition $column, string $tableName): void
    {
        [$type, $options] = match ($column->type) {
            'id' => [Types::BIGINT, ['autoincrement' => true, 'unsigned' => true]],
            'uuid' => [Types::GUID, []],
            'string' => [Types::STRING, ['length' => $column->length]],
            'text' => [Types::TEXT, []],
            'json' => [Types::JSON, ['platformOptions' => ['jsonb' => true]]],
            'integer' => [Types::INTEGER, []],
            'bigInteger' => [Types::BIGINT, []],
            'boolean' => [Types::BOOLEAN, []],
            'decimal' => [Types::DECIMAL, ['precision' => $column->precision, 'scale' => $column->scale]],
            'float' => [Types::FLOAT, []],
            'date' => [Types::DATE_MUTABLE, []],
            'dateTime' => [Types::DATETIME_MUTABLE, []],
            default => throw new \LogicException("Unknown column type {$column->type}."),
        };
        $options['notnull'] = $column->type === 'id' || !$column->nullable;
        if ($column->hasDefault) {
            $options['default'] = $column->default;
        }
        $table->addColumn($this->quote($column->name), $type, $options);

        if ($column->type === 'id' || $column->primary) {
            $table->setPrimaryKey([$this->quote($column->name)]);
        }
        if ($column->unique) {
            $table->addUniqueIndex([$this->quote($column->name)], $this->quote("{$tableName}_{$column->name}_unique"));
        }
    }

    /**
     * @param list<array{string, list<string>, string}> $indexes
     */
    private function addIndexes(Table $table, array $indexes): void
    {
        foreach ($indexes as [$type, $columns, $name]) {
            if ($columns === []) {
                throw new \LogicException("The index {$name} needs at least one column.");
            }
            $quoted = array_map($this->quote(...), $columns);
            $type === 'unique' ? $table->addUniqueIndex($quoted, $this->quote($name)) : $table->addIndex($quoted, $this->quote($name));
        }
    }
}
