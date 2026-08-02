<?php

declare(strict_types=1);

namespace Zana\Database\Migrations;

use Zana\Database\Contracts\ConnectionInterface;

/**
 * Base class for database migrations.
 *
 * Migration files should return an anonymous class extending this base class,
 * for example:
 *
 *     return new class extends Migration
 *     {
 *         public function up(ConnectionInterface $connection): void
 *         {
 *             $connection->execute('CREATE TABLE ...');
 *         }
 *
 *         public function down(ConnectionInterface $connection): void
 *         {
 *             $connection->execute('DROP TABLE ...');
 *         }
 *     };
 */
abstract class Migration
{
    /**
     * Apply the migration.
     */
    abstract public function up(ConnectionInterface $connection): void;

    /**
     * Reverse the migration.
     */
    abstract public function down(ConnectionInterface $connection): void;
}

