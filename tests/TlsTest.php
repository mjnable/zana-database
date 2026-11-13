<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Core\Application;
use Zana\Core\Inspection\Severity;
use Zana\Database\ConnectionFactory;
use Zana\Database\DatabaseException;
use Zana\Database\DatabaseServiceProvider;
use Zana\Database\Inspection\DatabaseTlsCheck;

/**
 * TLS for database connections (FE-78): the settings reach the driver, and
 * database.tls asks the server whether the live connection is encrypted.
 * Written to hold whether the test server offers TLS or not.
 */
final class TlsTest extends TestCase
{
    public function testAnUnknownSslModeIsRefusedBeforeConnecting(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('sslmode "requre" is not one of');

        ConnectionFactory::create(['driver' => 'pgsql', 'host' => '203.0.113.1', 'sslmode' => 'requre']);
    }

    public function testPostgresTlsSettingsReachTheDriver(): void
    {
        $settings = $this->settings('pgsql');
        try {
            $db = ConnectionFactory::create($settings + ['sslmode' => 'require']);
        } catch (DatabaseException $e) {
            // A server without TLS refuses a connection that requires it: the setting arrived.
            $this->assertStringContainsStringIgnoringCase('ssl', $e->getMessage());

            return;
        }
        $this->assertTrue((bool) ($db->select('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')[0]['ssl'] ?? false), 'a server with TLS gives an encrypted connection');
    }

    public function testMysqlTlsSettingsReachTheDriver(): void
    {
        $this->expectException(DatabaseException::class);

        ConnectionFactory::create($this->settings('mysql') + ['ssl_ca' => sys_get_temp_dir() . '/zana-no-such-ca.pem', 'ssl_verify' => true]);
    }

    public function testMysqlOptionsKeepTheirAttributeNumbers(): void
    {
        $options = (new \ReflectionMethod(ConnectionFactory::class, 'mysqlTls'))->invoke(null, 'mysql', ['ssl_ca' => '/ca.pem', 'ssl_verify' => 'true']);

        // Spread into an array literal, integer keys are renumbered from 0, and TLS silently never applies.
        $this->assertSame([\Pdo\Mysql::ATTR_SSL_CA => '/ca.pem', \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true], $options);
    }

    public function testTheCheckAsksTheServerAboutTheLiveConnection(): void
    {
        foreach (['pgsql', 'mysql'] as $driver) {
            $settings = Drivers::settings($driver);
            if (is_string($settings)) {
                continue;
            }
            foreach (['testing' => Severity::Warning, 'production' => Severity::Error] as $environment => $severity) {
                $app = Application::configure(sys_get_temp_dir() . '/zana-database-tls')
                    ->withEnvironment($environment)
                    ->withConfiguration(['database.connections.main' => $settings, 'security.secret' => str_repeat('s', 40)])
                    ->withProviders(DatabaseServiceProvider::class)
                    ->build();

                $this->assertSame([], (new DatabaseTlsCheck())->findings($app), "{$driver}: a server on this machine needs no TLS");

                $remote = (new DatabaseTlsCheck(localHosts: []))->findings($app);   // as if the server were elsewhere
                if ($remote === []) {
                    continue;   // the test server encrypts: nothing to report
                }
                $this->assertSame('database.tls', $remote[0]->id);
                $this->assertSame($severity, $remote[0]->severity, "{$driver} in {$environment}");
                $this->assertStringContainsString('not encrypted', $remote[0]->message);
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(string $driver): array
    {
        $settings = Drivers::settings($driver);
        if (is_string($settings)) {
            $this->markTestSkipped($settings);
        }

        return $settings;
    }
}
