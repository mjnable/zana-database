<?php

declare(strict_types=1);

namespace Zana\Database\Migration;

use Zana\Database\DatabaseException;

/**
 * Thrown for invalid migration files and failed migrations.
 */
final class MigrationException extends DatabaseException
{
}
