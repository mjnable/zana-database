<?php

declare(strict_types=1);

namespace Zana\Database\Query;

use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseException;

/**
 * Builds and runs SQL for one table. Values are always bound, never interpolated.
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    /** @var list<string> */
    private array $columns = ['*'];

    private bool $distinct = false;

    /** @var list<array{boolean: string, sql: string, bindings: list<mixed>}> */
    private array $wheres = [];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<string> */
    private array $orders = [];

    /** @var list<string> */
    private array $groups = [];

    private ?int $limit = null;

    private ?int $offset = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
    ) {
    }

    public function select(string ...$columns): self
    {
        $this->columns = $columns === [] ? ['*'] : array_values($columns);

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * where('age', 18) means age = 18; where('age', '>=', 18) uses the operator.
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        return func_num_args() === 2
            ? $this->addComparison('AND', $column, '=', $operatorOrValue)
            : $this->addComparison('AND', $column, (string) $operatorOrValue, $value);
    }

    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        return func_num_args() === 2
            ? $this->addComparison('OR', $column, '=', $operatorOrValue)
            : $this->addComparison('OR', $column, (string) $operatorOrValue, $value);
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->addIn($column, $values, false);
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->addIn($column, $values, true);
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ['boolean' => 'AND', 'sql' => $this->wrap($column) . ' IS NULL', 'bindings' => []];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = ['boolean' => 'AND', 'sql' => $this->wrap($column) . ' IS NOT NULL', 'bindings' => []];

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->assertOperator($operator);
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new DatabaseException("Unknown join type: {$type}.");
        }

        $this->joins[] = sprintf('%s JOIN %s ON %s %s %s', $type, $this->wrap($table), $this->wrap($first), strtoupper($operator), $this->wrap($second));

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * @param string $direction "asc" or "desc", case-insensitive
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new DatabaseException("Unknown sort direction: {$direction}.");
        }

        $this->orders[] = $this->wrap($column) . ' ' . $direction;

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        array_push($this->groups, ...array_map($this->wrap(...), $columns));

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        [$sql, $bindings] = $this->toSql();

        return $this->connection->select($sql, $bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $clone = clone $this;
        [$sql, $bindings] = $clone->limit(1)->toSql();

        return $this->connection->first($sql, $bindings);
    }

    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();

        return $row === null ? null : array_values($row)[0];
    }

    /**
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        return array_map(static fn (array $row): mixed => array_values($row)[0], (clone $this)->select($column)->get());
    }

    public function count(): int
    {
        $clone = clone $this;
        $clone->columns = ['COUNT(*) AS aggregate'];
        $clone->orders = [];
        [$sql, $bindings] = $clone->toSql(raw: true);

        return (int) $this->connection->scalar($sql, $bindings);
    }

    public function exists(): bool
    {
        return $this->first() !== null;
    }

    /**
     * Insert one row (an associative array) or several (a list of rows with the same columns).
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     */
    public function insert(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_is_list($values) ? $values : [$values];
        $columns = array_keys($rows[0]);

        $bindings = [];
        $tuples = [];
        foreach ($rows as $row) {
            if (array_keys($row) !== $columns) {
                throw new DatabaseException('Every inserted row must have the same columns in the same order.');
            }
            $tuples[] = '(' . implode(', ', array_fill(0, count($row), '?')) . ')';
            array_push($bindings, ...array_values($row));
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->wrap($this->table),
            implode(', ', array_map($this->wrap(...), $columns)),
            implode(', ', $tuples)
        );

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $values
     * @param bool $force Allow updating every row (no where clause)
     */
    public function update(array $values, bool $force = false): int
    {
        $this->assertFiltered('update', $force);

        $sets = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            $sets[] = $this->wrap($column) . ' = ?';
            $bindings[] = $value;
        }

        [$where, $whereBindings] = $this->compileWheres();
        $sql = 'UPDATE ' . $this->wrap($this->table) . ' SET ' . implode(', ', $sets) . $where;

        return $this->connection->execute($sql, [...$bindings, ...$whereBindings]);
    }

    /**
     * @param bool $force Allow deleting every row (no where clause)
     */
    public function delete(bool $force = false): int
    {
        $this->assertFiltered('delete', $force);

        [$where, $bindings] = $this->compileWheres();

        return $this->connection->execute('DELETE FROM ' . $this->wrap($this->table) . $where, $bindings);
    }

    /**
     * @return array{string, list<mixed>}
     */
    public function toSql(bool $raw = false): array
    {
        $columns = $raw ? implode(', ', $this->columns) : implode(', ', array_map($this->wrap(...), $this->columns));

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $columns . ' FROM ' . $this->wrap($this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        [$where, $bindings] = $this->compileWheres();
        $sql .= $where;

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ($this->limit === null ? ' LIMIT ' . PHP_INT_MAX : '') . ' OFFSET ' . $this->offset;
        }

        return [$sql, $bindings];
    }

    /**
     * @return array{string, list<mixed>}
     */
    private function compileWheres(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }

        $sql = '';
        $bindings = [];
        foreach ($this->wheres as $index => $where) {
            $sql .= ($index === 0 ? ' WHERE ' : " {$where['boolean']} ") . $where['sql'];
            array_push($bindings, ...$where['bindings']);
        }

        return [$sql, $bindings];
    }

    private function addComparison(string $boolean, string $column, string $operator, mixed $value): self
    {
        $operator = strtoupper($operator);
        $this->assertOperator($operator);

        if ($value === null && in_array($operator, ['=', '!=', '<>'], true)) {
            $this->wheres[] = ['boolean' => $boolean, 'sql' => $this->wrap($column) . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL'), 'bindings' => []];

            return $this;
        }

        $this->wheres[] = ['boolean' => $boolean, 'sql' => $this->wrap($column) . " {$operator} ?", 'bindings' => [$value]];

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    private function addIn(string $column, array $values, bool $not): self
    {
        if ($values === []) {
            $this->wheres[] = ['boolean' => 'AND', 'sql' => $not ? '1 = 1' : '0 = 1', 'bindings' => []];

            return $this;
        }

        $this->wheres[] = [
            'boolean' => 'AND',
            'sql' => $this->wrap($column) . ($not ? ' NOT IN (' : ' IN (') . implode(', ', array_fill(0, count($values), '?')) . ')',
            'bindings' => $values,
        ];

        return $this;
    }

    private function assertOperator(string $operator): void
    {
        if (!in_array(strtoupper($operator), self::OPERATORS, true)) {
            throw new DatabaseException("Unsupported operator: {$operator}.");
        }
    }

    private function assertFiltered(string $action, bool $force): void
    {
        if ($this->wheres === [] && !$force) {
            throw new DatabaseException("Refusing to {$action} every row of {$this->table} without a where clause; pass force: true to do it on purpose.");
        }
    }

    private function wrap(string $identifier): string
    {
        return $this->connection->grammar()->wrap($identifier);
    }
}
