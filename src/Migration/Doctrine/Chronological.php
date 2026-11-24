<?php

declare(strict_types=1);

namespace Zana\Database\Migration\Doctrine;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * @internal Migrations in the order of the date in their name, across directories and kinds (ADR-035):
 * 2026_10_26_120300_create_x and Modules\Work\...\Version20261124120000 compare as 20261026120300 and
 * 20261124120000. Two of the same second compare by name.
 */
final class Chronological implements Comparator
{
    public function compare(Version $a, Version $b): int
    {
        return [self::key((string) $a), (string) $a] <=> [self::key((string) $b), (string) $b];
    }

    public static function key(string $name): string
    {
        $base = substr($name, (int) strrpos('\\' . $name, '\\'));
        if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{6})_/', $base, $m) === 1) {
            return $m[1] . $m[2] . $m[3] . $m[4];
        }
        if (preg_match('/^Version(\d{14})$/', $base, $m) === 1) {
            return $m[1];
        }

        return $base;
    }
}
