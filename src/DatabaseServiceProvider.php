<?php

declare(strict_types=1);

namespace Zana\Database;

use Zana\Configuration\ConfigurationInterface;
use Zana\Console\ConsoleKernel;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\ServiceProvider;
use Zana\Database\Console\MakeMigrationCommand;
use Zana\Database\Console\MigrateCommand;
use Zana\Database\Console\RollbackCommand;
use Zana\Database\Console\StatusCommand;
use Zana\Database\Migration\MigrationRepository;
use Zana\Database\Migration\Migrator;
use Zana\Ddd\Domain\UnitOfWorkInterface;

/**
 * Registers connections, the scoped unit of work, migrations and their commands.
 * Migrations come from database/migrations and each module's
 * Infrastructure/Persistence/Migrations, in module order.
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(DatabaseManager::class, static fn (ServiceResolverInterface $r): DatabaseManager => new DatabaseManager(
                $r->get(ConfigurationInterface::class),
                $r->get(ApplicationInterface::class)->basePath(),
            ))->uses(ConfigurationInterface::class, ApplicationInterface::class)
            ->singleton(ConnectionInterface::class, static fn (ServiceResolverInterface $r): ConnectionInterface => $r->get(DatabaseManager::class)->connection())->uses(DatabaseManager::class)
            ->scoped(UnitOfWorkInterface::class, TransactionalUnitOfWork::class)
            ->singleton(MigrationRepository::class)
            ->singleton(Migrator::class, static function (ServiceResolverInterface $r): Migrator {
                $app = $r->get(ApplicationInterface::class);
                $paths = [$app->path('database', 'migrations')];
                foreach ($app->modules() as $module) {
                    $paths[] = $module->path('Infrastructure', 'Persistence', 'Migrations');
                }

                return new Migrator($r->get(ConnectionInterface::class), $r->get(MigrationRepository::class), $paths);
            })->uses(ApplicationInterface::class, ConnectionInterface::class, MigrationRepository::class);

        foreach ([MigrateCommand::class, RollbackCommand::class, StatusCommand::class, MakeMigrationCommand::class] as $command) {
            $services->singleton($command)->tag(ConsoleKernel::TAG, $command);
        }
    }

    public function origin(): string
    {
        return 'package:mj-zana/database';
    }
}
