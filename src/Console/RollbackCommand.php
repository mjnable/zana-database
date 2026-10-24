<?php

declare(strict_types=1);

namespace Zana\Database\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Database\Migration\Migrator;

#[AsCommand(name: 'migrate:rollback', description: 'Roll back the last batch of migrations')]
final class RollbackCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('step', null, InputOption::VALUE_REQUIRED, 'How many batches to roll back', '1');
    }

    public function handle(Migrator $migrator, InputInterface $input, SymfonyStyle $io): int
    {
        $step = filter_var($input->getOption('step'), FILTER_VALIDATE_INT);
        if (!is_int($step) || $step < 1) {
            $io->error('--step must be a positive integer.');

            return self::INVALID;
        }

        $reverted = $migrator->rollback($step);
        if ($reverted === []) {
            $io->success('Nothing to roll back.');

            return self::SUCCESS;
        }

        $io->listing($reverted);
        $io->success(sprintf('Rolled back %d migration%s.', count($reverted), count($reverted) === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
