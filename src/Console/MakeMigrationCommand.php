<?php

declare(strict_types=1);

namespace Zana\Database\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\ApplicationInterface;

#[AsCommand(name: 'make:migration', description: 'Create a migration file')]
final class MakeMigrationCommand extends Command
{
    private const STUB = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Zana\Database\Migration\Migration;
        use Zana\Database\Schema\Blueprint;
        use Zana\Database\Schema\Schema;

        return new class () extends Migration {
            public function up(Schema $schema): void
            {
                $schema->create('%1$s', static function (Blueprint $table): void {
                    $table->id();
                    $table->timestamps();
                });
            }

            public function down(Schema $schema): void
            {
                $schema->dropIfExists('%1$s');
            }
        };

        PHP;

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Description, e.g. create_patients_table')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory relative to the base path', 'database/migrations');
    }

    public function handle(InputInterface $input, SymfonyStyle $io, ApplicationInterface $app): int
    {
        $name = strtolower(trim((string) $input->getArgument('name')));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $io->error('The name may only contain lower-case letters, digits and underscores, starting with a letter.');

            return self::INVALID;
        }

        $directory = $app->path((string) $input->getOption('path'));
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $io->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $table = preg_match('/^create_(\w+?)(_table)?$/', $name, $match) === 1 ? $match[1] : 'table_name';
        $file = $directory . '/' . date('Y_m_d_His') . "_{$name}.php";
        file_put_contents($file, sprintf(self::STUB, $table));

        $io->success("Created {$file}");

        return self::SUCCESS;
    }
}
