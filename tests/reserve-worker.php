<?php

declare(strict_types=1);

// One buyer process of PlatformTest's concurrency test: tries $argv[2] times to reserve one seat on
// counter row 1 with a single conditional update, and prints how many reservations succeeded.

use Zana\Database\ConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null));
$settings = json_decode($arguments[1], true, flags: JSON_THROW_ON_ERROR);
assert(is_array($settings));
$db = ConnectionFactory::create($settings);
if ($db->driver() === 'sqlite') {
    $db->execute('PRAGMA busy_timeout = 10000');
}

$won = 0;
for ($attempt = 0; $attempt < (int) $arguments[2]; $attempt++) {
    $won += $db->table('counters')->where('id', 1)->whereColumn('sold', '<=', 'capacity', -1)->increment('sold');
}
echo $won;
