<?php

declare(strict_types=1);

namespace Zana\Database\Schema;

/**
 * A foreign key: foreign('patient_id')->references('id')->on('patients')->onDelete('cascade').
 */
final class ForeignKeyDefinition
{
    public string $references = 'id';

    public string $table = '';

    public ?string $onDelete = null;

    public function __construct(public readonly string $column)
    {
    }

    public function references(string $column): self
    {
        $this->references = $column;

        return $this;
    }

    public function on(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * @param string $action "cascade", "set null", "restrict" or "no action"
     */
    public function onDelete(string $action): self
    {
        if (!in_array(strtolower($action), ['cascade', 'set null', 'restrict', 'no action'], true)) {
            throw new \InvalidArgumentException("Unknown ON DELETE action: {$action}.");
        }

        $this->onDelete = $action;

        return $this;
    }
}
