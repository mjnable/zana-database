<?php

declare(strict_types=1);

namespace Zana\Database\Tests;

use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseException;

/**
 * FE-77: the race-safe insert as applications write it. PHPStan at level 8
 * analyses this file with the package, so an annotation that lets it assume
 * a second read returns the first one's result ("If condition is always
 * false") fails the build, not an application's.
 */
final readonly class RaceSafeInsert
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function record(string $reference): bool
    {
        if ($this->db->table('payments')->where('reference', $reference)->exists()) {
            return false;
        }
        try {
            $this->db->transaction(fn (ConnectionInterface $db) => $db->table('payments')->insert(['reference' => $reference]));
        } catch (DatabaseException $e) {
            // Another request inserted it in between: a duplicate, not an error.
            if ($this->db->table('payments')->where('reference', $reference)->exists()) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function total(): int
    {
        $before = $this->db->table('payments')->count();
        $this->db->table('payments')->insert(['reference' => 'x']);
        if ($this->db->table('payments')->count() === $before) {
            return -1;
        }

        return (int) $this->db->scalar('SELECT COUNT(*) FROM payments');
    }
}
