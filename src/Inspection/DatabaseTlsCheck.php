<?php

declare(strict_types=1);

namespace Zana\Database\Inspection;

use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseManager;

/**
 * database.tls (FE-78, 2.20): a connection to a database on another host is
 * encrypted. It asks the server about the live connection rather than
 * reading the configuration, because a setting that never reached the
 * driver (PGSSLMODE in .env, on Windows) looked right and was not. An error
 * in production, a warning elsewhere.
 */
final readonly class DatabaseTlsCheck implements ApplicationCheck
{
    /**
     * @param list<string> $localHosts Hosts on this machine, where TLS is not needed
     */
    public function __construct(private array $localHosts = ['localhost', '127.0.0.1', '::1', ''])
    {
    }

    public function findings(ApplicationInterface $application): array
    {
        $container = $application->container();
        $config = $container->get(ConfigurationInterface::class);
        $databases = $container->get(DatabaseManager::class);
        assert($config instanceof ConfigurationInterface && $databases instanceof DatabaseManager);

        $findings = [];
        foreach ($config->array('database.connections', []) as $name => $settings) {
            if (!is_array($settings) || !in_array($settings['driver'] ?? null, ['pgsql', 'mysql'], true)) {
                continue;
            }
            $host = is_string($settings['host'] ?? null) ? $settings['host'] : '127.0.0.1';
            if ($this->isLocal($host)) {
                continue;
            }

            try {
                $encrypted = self::encrypted($databases->connection((string) $name), (string) $settings['driver']);
            } catch (\Throwable $e) {
                $findings[] = new Finding('database.tls', Severity::Warning, "Cannot tell whether the \"{$name}\" connection to {$host} is encrypted: " . strtok($e->getMessage(), "\n"));
                continue;
            }
            if ($encrypted) {
                continue;
            }
            $findings[] = new Finding(
                'database.tls',
                $application->environment()->isProduction() ? Severity::Error : Severity::Warning,
                "The \"{$name}\" connection to {$host} is not encrypted: passwords and data cross the network in clear.",
                hint: $settings['driver'] === 'pgsql'
                    ? "Set sslmode to verify-full and sslrootcert to the server's CA file in database.connections.{$name} (APP_DATABASE__CONNECTIONS__" . strtoupper((string) $name) . '__SSLMODE). PGSSLMODE in .env does not reach the driver.'
                    : "Set ssl_ca to the server's CA file and ssl_verify to true in database.connections.{$name}.",
            );
        }

        return $findings;
    }

    private function isLocal(string $host): bool
    {
        return in_array(strtolower($host), $this->localHosts, true) || str_starts_with($host, '/') || ($this->localHosts !== [] && str_starts_with($host, '127.'));
    }

    /**
     * What the server says about this very connection.
     */
    private static function encrypted(ConnectionInterface $db, string $driver): bool
    {
        if ($driver === 'pgsql') {
            $row = $db->select('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')[0] ?? null;

            return is_array($row) && in_array($row['ssl'] ?? null, [true, 't', 1, '1'], true);
        }
        $row = $db->select("SHOW SESSION STATUS LIKE 'Ssl_cipher'")[0] ?? null;
        $cipher = is_array($row) ? ($row['Value'] ?? $row['value'] ?? '') : '';

        return is_string($cipher) && $cipher !== '';
    }
}
