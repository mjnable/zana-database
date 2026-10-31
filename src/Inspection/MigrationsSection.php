<?php

declare(strict_types=1);

namespace Zana\Database\Inspection;

use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\InspectionSection;
use Zana\Database\Migration\Migrator;

/**
 * The "migrations" section of the inspect command (ADR-023): every migration,
 * whether it ran, and its batch. When the database cannot be read, the
 * section says why instead of failing the whole command.
 */
final readonly class MigrationsSection implements InspectionSection
{
    /**
     * @param \Closure(): Migrator $migrator Resolved when used: creating the migrator connects to the database
     */
    public function __construct(private \Closure $migrator)
    {
    }

    public function name(): string
    {
        return 'migrations';
    }

    public function inspect(ApplicationInterface $application): array
    {
        try {
            return ($this->migrator)()->status();
        } catch (\Throwable $e) {
            return ['error' => 'The migrations cannot be read: ' . strtok($e->getMessage(), "\n")];
        }
    }
}
