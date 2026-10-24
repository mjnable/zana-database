<?php

declare(strict_types=1);

namespace Zana\Database\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Database\Migration\Migrator;

#[AsCommand(name: 'migrate:status', description: 'Show which migrations ran')]
final class StatusCommand extends Command
{
    public function handle(Migrator $migrator, SymfonyStyle $io): int
    {
        $rows = array_map(
            static fn (array $s): array => [$s['migration'], $s['ran'] ? 'Ran' : 'Pending', $s['batch'] ?? ''],
            $migrator->status()
        );

        $rows === [] ? $io->note('No migrations found.') : $io->table(['Migration', 'Status', 'Batch'], $rows);

        return self::SUCCESS;
    }
}
