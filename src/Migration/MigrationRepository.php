<?php

declare(strict_types=1);

namespace Zana\Database\Migration;

use Zana\Database\ConnectionInterface;
use Zana\Database\Schema\Blueprint;

/**
 * Records which migrations ran, in which batch, in the zana_migrations table.
 */
final readonly class MigrationRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'zana_migrations',
    ) {
    }

    public function ensureTable(): void
    {
        if ($this->connection->schema()->hasTable($this->table)) {
            return;
        }

        $this->connection->schema()->create($this->table, static function (Blueprint $table): void {
            $table->string('migration')->primary();
            $table->integer('batch');
            $table->dateTime('ran_at');
        });
    }

    /**
     * @return array<string, int> migration => batch
     */
    public function ran(): array
    {
        $this->ensureTable();

        $ran = [];
        foreach ($this->connection->table($this->table)->orderBy('migration')->get() as $row) {
            $ran[(string) $row['migration']] = (int) $row['batch'];
        }

        return $ran;
    }

    public function lastBatch(): int
    {
        $this->ensureTable();

        return (int) $this->connection->scalar('SELECT MAX(' . $this->connection->grammar()->wrap('batch') . ') FROM ' . $this->connection->grammar()->wrap($this->table));
    }

    public function log(string $migration, int $batch): void
    {
        $this->connection->table($this->table)->insert([
            'migration' => $migration,
            'batch' => $batch,
            'ran_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(string $migration): void
    {
        $this->connection->table($this->table)->where('migration', $migration)->delete();
    }
}
