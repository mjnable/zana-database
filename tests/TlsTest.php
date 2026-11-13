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
     * With a PostgreSQL that offers TLS: ZANA_TEST_PGSQL_TLS_PORT, its CA in ZANA_TEST_PGSQL_TLS_CA, and an unrelated
     * CA in ZANA_TEST_TLS_OTHER_CA. tests/tls-server.md says how to start one beside the usual server.
     */
    public function testPostgresVerifyFullEncryptsAndRefusesAnotherCa(): void
    {
        [$port, $ca, $other] = [getenv('ZANA_TEST_PGSQL_TLS_PORT'), getenv('ZANA_TEST_PGSQL_TLS_CA'), getenv('ZANA_TEST_TLS_OTHER_CA')];
        if (!is_string($port) || !is_string($ca) || !is_string($other) || !extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('No PostgreSQL with TLS: set ZANA_TEST_PGSQL_TLS_PORT, ZANA_TEST_PGSQL_TLS_CA and ZANA_TEST_TLS_OTHER_CA.');
        }
        $settings = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'postgres', 'username' => 'postgres', 'password' => (string) getenv('ZANA_TEST_PGSQL_TLS_PASSWORD')];

        $db = ConnectionFactory::create($settings + ['sslmode' => 'verify-full', 'sslrootcert' => $ca]);
        $this->assertTrue((bool) ($db->select('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')[0]['ssl'] ?? false), 'verify-full with the right CA: encrypted');
        $this->assertSame([], (new DatabaseTlsCheck(localHosts: []))->findings($this->app($settings + ['sslmode' => 'verify-full', 'sslrootcert' => $ca], 'production')), 'and check is satisfied');

        $plain = ConnectionFactory::create($settings + ['sslmode' => 'disable']);
        $this->assertFalse((bool) ($plain->select('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')[0]['ssl'] ?? true), 'disable: plain');

        // A Windows path, with backslashes and a space, reaches libpq as written.
        $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zana tls ' . bin2hex(random_bytes(3));
        mkdir($folder);
        copy($ca, $folder . DIRECTORY_SEPARATOR . "it's ca.pem");
        try {
            $spaced = ConnectionFactory::create($settings + ['sslmode' => 'verify-full', 'sslrootcert' => $folder . DIRECTORY_SEPARATOR . "it's ca.pem"]);
            $this->assertTrue((bool) ($spaced->select('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')[0]['ssl'] ?? false), 'a path with backslashes, a space and a quote');
        } finally {
            unlink($folder . DIRECTORY_SEPARATOR . "it's ca.pem");
            rmdir($folder);
        }

        $this->expectException(DatabaseException::class);
        ConnectionFactory::create($settings + ['sslmode' => 'verify-full', 'sslrootcert' => $other]);   // a certificate this CA did not sign
    }

    /**
     * With the MySQL server's own CA in ZANA_TEST_MYSQL_TLS_CA (MySQL writes ca.pem in its data folder).
     */
    public function testMysqlWithTheServerCaEncryptsAndRefusesAnotherCa(): void
    {
        [$ca, $other] = [getenv('ZANA_TEST_MYSQL_TLS_CA'), getenv('ZANA_TEST_TLS_OTHER_CA')];
        if (!is_string($ca) || !is_string($other)) {
            $this->markTestSkipped('No MySQL CA: set ZANA_TEST_MYSQL_TLS_CA and ZANA_TEST_TLS_OTHER_CA.');
        }
        $settings = $this->settings('mysql');

        $db = ConnectionFactory::create($settings + ['ssl_ca' => $ca, 'ssl_verify' => false]);
        $this->assertNotSame('', $db->select("SHOW SESSION STATUS LIKE 'Ssl_cipher'")[0]['Value'] ?? '', 'the server CA: encrypted');
        $this->assertSame([], (new DatabaseTlsCheck(localHosts: []))->findings($this->app($settings + ['ssl_ca' => $ca, 'ssl_verify' => false], 'production')));

        $this->expectException(DatabaseException::class);
        ConnectionFactory::create($settings + ['ssl_ca' => $other, 'ssl_verify' => true]);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function app(array $connection, string $environment): Application
    {
        return Application::configure(sys_get_temp_dir() . '/zana-database-tls')
            ->withEnvironment($environment)
            ->withConfiguration(['database.connections.main' => $connection, 'security.secret' => str_repeat('s', 40)])
            ->withProviders(DatabaseServiceProvider::class)
            ->build();
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
