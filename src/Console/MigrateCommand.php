<?php

declare(strict_types=1);

namespace Zana\Database\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Database\Migration\Migrator;

#[AsCommand(name: 'migrate', description: 'Run the pending database migrations')]
final class MigrateCommand extends Command
{
    public function handle(Migrator $migrator, SymfonyStyle $io): int
    {
        $ran = $migrator->migrate();

        if ($ran === []) {
            $io->success('Nothing to migrate.');

            return self::SUCCESS;
        }

        $io->listing($ran);
        $io->success(sprintf('Ran %d migration%s.', count($ran), count($ran) === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
