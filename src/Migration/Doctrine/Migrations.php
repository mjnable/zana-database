<?php

declare(strict_types=1);

namespace Zana\Database\Migration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\MigrationNotAvailable;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsSet;
use Doctrine\Migrations\MigrationsRepository;
use Doctrine\Migrations\Version\Version;
use Psr\Log\LoggerInterface;
use Zana\Database\ConnectionInterface;
use Zana\Database\Migration\MigrationException;

/**
 * @internal The migrations of every directory (ADR-035), read again at each operation so a file added since is seen:
 *
 * - Zana files, YYYY_MM_DD_HHMMSS_name.php returning a Migration, named by their file (2026_10_26_120300_create_x);
 * - Doctrine classes, VersionYYYYMMDDHHMMSS.php declaring that class, extending AbstractMigration, named by their
 *   class with its namespace, as Doctrine names them.
 */
final class Migrations implements MigrationsRepository
{
    public const string FILE_NAME = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/';

    public const string CLASS_NAME = '/^Version\d{14}$/';

    /** @var array<string, class-string<AbstractMigration>> path => class, for files already required */
    private static array $classes = [];

    /** @var array<string, AvailableMigration>|null */
    private ?array $migrations = null;

    /** @var array<string, string> name => path */
    private array $files = [];

    /**
     * @param list<string> $paths
     */
    public function __construct(private readonly array $paths, private readonly Connection $dbal, private readonly ConnectionInterface $zana, private readonly LoggerInterface $logger)
    {
    }

    /**
     * Forget what was read, so the next call reads the directories again.
     */
    public function refresh(): void
    {
        $this->migrations = null;
        $this->files = [];
    }

    public function hasMigration(string $version): bool
    {
        return isset($this->load()[$version]);
    }

    public function getMigration(Version $version): AvailableMigration
    {
        return $this->load()[(string) $version] ?? throw MigrationNotAvailable::forVersion($version);
    }

    public function getMigrations(): AvailableMigrationsSet
    {
        return new AvailableMigrationsSet(array_values($this->load()));
    }

    /**
     * @return array<string, string> name => path
     */
    public function files(): array
    {
        $this->load();

        return $this->files;
    }

    /**
     * @return array<string, AvailableMigration>
     */
    private function load(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }
        $migrations = [];
        $this->files = [];
        foreach ($this->paths as $path) {
            foreach (glob(rtrim($path, '/\\') . '/*.php') ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (preg_match(self::CLASS_NAME, $name) === 1) {
                    $class = self::declaredClass($file, $name);
                    $this->add($migrations, $class, $file, new $class($this->dbal, $this->logger));
                } elseif (preg_match(self::FILE_NAME, $name) === 1) {
                    $this->add($migrations, $name, $file, new FileMigration($this->dbal, $this->logger, $this->zana, $file));
                } else {
                    throw new MigrationException("Migration file {$file} must be named YYYY_MM_DD_HHMMSS_description.php (lower-case, underscores), or VersionYYYYMMDDHHMMSS.php for a Doctrine migration class.");
                }
            }
        }
        ksort($this->files);

        return $this->migrations = $migrations;
    }

    /**
     * The class a VersionYYYYMMDDHHMMSS.php file declares: VersionYYYYMMDDHHMMSS, in the file's namespace.
     *
     * @return class-string<AbstractMigration>
     */
    private static function declaredClass(string $file, string $name): string
    {
        $real = (string) realpath($file);
        if (isset(self::$classes[$real])) {
            return self::$classes[$real];
        }
        $namespace = preg_match('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m', (string) file_get_contents($file), $match) === 1 ? $match[1] . '\\' : '';
        $class = $namespace . $name;
        if (!class_exists($class, false)) {
            (static function (string $file): void {
                require_once $file;
            })($file);
        }
        if (!class_exists($class, false) || !is_subclass_of($class, AbstractMigration::class)) {
            throw new MigrationException("{$file} must declare the class {$class}, extending " . AbstractMigration::class . '.');
        }
        if ((string) realpath((string) (new \ReflectionClass($class))->getFileName()) !== $real) {
            throw new MigrationException("Migration {$class} exists in two files: " . (new \ReflectionClass($class))->getFileName() . " and {$file}.");
        }

        return self::$classes[$real] = $class;
    }

    /**
     * @param array<string, AvailableMigration> $migrations
     */
    private function add(array &$migrations, string $name, string $file, AbstractMigration $migration): void
    {
        if (isset($this->files[$name])) {
            throw new MigrationException("Migration {$name} exists in two directories: {$this->files[$name]} and {$file}.");
        }
        $this->files[$name] = $file;
        $migrations[$name] = new AvailableMigration(new Version($name), $migration);
    }
}
