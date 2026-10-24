<?php

declare(strict_types=1);

namespace Zana\Database\Schema;

/**
 * One column of a table being created, with fluent modifiers.
 */
final class ColumnDefinition
{
    public bool $nullable = false;

    public bool $hasDefault = false;

    public mixed $default = null;

    public bool $unique = false;

    public bool $primary = false;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $length = 255,
        public readonly int $precision = 10,
        public readonly int $scale = 2,
    ) {
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }
}
