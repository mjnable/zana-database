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

    public function integer(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'integer'));
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
