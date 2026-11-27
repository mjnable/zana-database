<?php

declare(strict_types=1);

// One process of MigrationLockTest (FE-85). Arguments: the connection settings as JSON, then either
//   migrate <directory> <lock timeout> <dbal|pdo>   one migrate, as a deployment step runs it; prints the result as JSON
//   hold <seconds>                                  takes the migration lock, prints "locked", and keeps it that long

use Zana\Database\ConnectionFactory;
use Zana\Database\Migration\MigrationLock;
use Zana\Database\Migration\MigrationRepository;
use Zana\Database\Migration\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null));
$settings = json_decode($arguments[1], true, flags: JSON_THROW_ON_ERROR);
assert(is_array($settings));

if ($arguments[2] === 'hold') {
    $db = ConnectionFactory::create($settings);
    (new MigrationLock($db, 0))->hold(static function () use ($arguments): void {
        echo "locked\n";
        fflush(STDOUT);
        sleep((int) ($arguments[3] ?? 5));
    });
    exit(0);
}

[$directory, $timeout, $engine] = [(string) $arguments[3], (int) $arguments[4], (string) $arguments[5]];
$db = $engine === 'pdo' ? ConnectionFactory::createPdo($settings) : ConnectionFactory::create($settings);
$start = microtime(true);
try {
    $ran = (new Migrator($db, new MigrationRepository($db), [$directory], lockTimeout: $timeout))->migrate();
    echo json_encode(['ran' => $ran, 'seconds' => round(microtime(true) - $start, 1)]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage(), 'seconds' => round(microtime(true) - $start, 1)]);
}
