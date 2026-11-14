<?php

declare(strict_types=1);

namespace Zana\Database\Schema;

/**
 * Describes a table to create.
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var list<array{'index'|'unique', list<string>, string}> */
    private array $indexes = [];

    /** @var list<string> */
    private array $droppedColumns = [];

    /** @var list<string> */
    private array $droppedIndexes = [];

    /** @var list<array{string, string}> */
    private array $checks = [];

    public function __construct(public readonly string $table)
    {
    }

    /**
     * An auto-incrementing integer primary key.
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'id'));
    }

    /**
     * A UUID: `uuid` on PostgreSQL, text elsewhere. Store canonical UUIDs
     * (Uuid::v7()): PostgreSQL also accepts 32 hexadecimal digits and returns
     * them hyphenated, so an id stored as bin2hex(random_bytes(16)) is not
     * found by its own value there, while SQLite finds it (FE-74).
     */
    public function uuid(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'uuid'));
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'string', length: $length));
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'text'));
    }

    /**
     * A 2-byte integer where the database has one (2.20, FE-74): SMALLINT on
     * PostgreSQL and MySQL, INTEGER on SQLite.
     */
    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'smallInteger'));
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'integer'));
    }

    /**
     * A CHECK constraint (2.20, FE-74), so an invariant holds in the database
     * as well as in code. The expression is SQL written by the migration's
     * author, never by a user; name columns as the database expects them.
     * The constraint is named "{table}_{name}_check", as MySQL needs names
     * unique across the schema.
     *
     *     $t->check('sold_within_quantity', 'sold <= quantity');
     *
     * In table() it is refused on SQLite, which cannot add a constraint to an
     * existing table.
     */
    public function check(string $name, string $expression): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("The check name \"{$name}\" must be letters, digits and underscores.");
        }
        if (trim($expression) === '') {
            throw new \InvalidArgumentException("The check {$name} needs an expression.");
        }
        $this->checks[] = ["{$this->table}_{$name}_check", $expression];
    }

    /**
     * @return list<array{string, string}> Each constraint's full name and expression
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'bigInteger'));
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'decimal', precision: $precision, scale: $scale));
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'float'));
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'boolean'));
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'date'));
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'dateTime'));
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'json'));
    }

    /**
     * Nullable created_at and updated_at date-time columns.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->addIndex('index', (array) $columns, $name);
    }

    /**
     * A unique index over several columns; for one column you can also use ->unique().
     *
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->addIndex('unique', (array) $columns, $name);
    }

    /**
     * Drop columns of an existing table (Schema::table() only).
     */
    public function dropColumn(string ...$columns): void
    {
        array_push($this->droppedColumns, ...array_values($columns));
    }

    /**
     * Drop an index of an existing table by name (Schema::table() only), e.g.
     * "measurements_patient_id_index" for index('patient_id') on measurements.
     */
    public function dropIndex(string $name): void
    {
        $this->droppedIndexes[] = $name;
    }

    /**
     * @return list<string>
     */
    public function droppedColumns(): array
    {
        return $this->droppedColumns;
    }

    /**
     * @return list<string>
     */
    public function droppedIndexes(): array
    {
        return $this->droppedIndexes;
    }

    /**
     * The foreign keys declared so far, without checking them (for alter validation).
     *
     * @return list<ForeignKeyDefinition>
     */
    public function declaredForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function foreign(string $column): ForeignKeyDefinition
    {
        return $this->foreignKeys[] = new ForeignKeyDefinition($column);
    }

    /**
     * @return list<ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeys(): array
    {
        foreach ($this->foreignKeys as $foreign) {
            if ($foreign->table === '') {
                throw new \LogicException("The foreign key on {$this->table}.{$foreign->column} needs on(table).");
            }
        }

        return $this->foreignKeys;
    }

    /**
     * @return list<array{'index'|'unique', list<string>, string}>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    private function add(ColumnDefinition $column): ColumnDefinition
    {
        return $this->columns[] = $column;
    }

    /**
     * @param 'index'|'unique' $type
     * @param list<string> $columns
     */
    private function addIndex(string $type, array $columns, ?string $name): void
    {
        $this->indexes[] = [$type, $columns, $name ?? $this->table . '_' . implode('_', $columns) . '_' . $type];
    }
}
