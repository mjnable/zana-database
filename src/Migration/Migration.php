<?php

declare(strict_types=1);

namespace Zana\Database\Migration;

use Zana\Database\Schema\Schema;

/**
 * A schema change. Migration files are named YYYY_MM_DD_HHMMSS_description.php
 * and return an instance:
 *
 *     return new class () extends Migration {
 *         public function up(Schema $schema): void { $schema->create('patients', fn (Blueprint $t) => $t->id()); }
 *         public function down(Schema $schema): void { $schema->drop('patients'); }
 *     };
 */
abstract class Migration
{
    abstract public function up(Schema $schema): void;

    abstract public function down(Schema $schema): void;
}
