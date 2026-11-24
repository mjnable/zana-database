<?php

declare(strict_types=1);

namespace Zana\Database\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Database\Migration\Migrator;

/**
 * @internal What the migrations said (write(), warnIf()) and the migrator's warnings, shown by migrate and
 * migrate:rollback, also when a migration failed.
 */
trait ShowsMigrationMessages
{
    private static function showMessages(Migrator $migrator, SymfonyStyle $io): void
    {
        foreach ($migrator->messages() as $message) {
            // Plain text: a "<" in what a migration says is not a console tag.
            $text = OutputFormatter::escape($message['message']);
            in_array($message['level'], ['warning', 'error', 'critical', 'alert', 'emergency'], true)
                ? $io->warning($text)
                : $io->text($text);
        }
    }
}
