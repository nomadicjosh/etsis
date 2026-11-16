<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisRestrictionPersonIndex extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_restrictions', static function (AlterTable $table): void {
            $table->index(['person_id', 'id'], 'sr_person_history');
        });
    }

    public function down(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Index rollback requires a database connection.');
        }
        $connection = $adapter->connection();
        // Installed SQLite/Oracle compilers incorrectly qualify the index with its table.
        if (in_array($connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['sqlite', 'oci'], true)) {
            $connection->query('DROP INDEX ' . $connection->quoteIdentifier('sr_person_history'));
            return;
        }
        $this->schema()->alter('sis_restrictions', static function (AlterTable $table): void {
            $table->dropIndex('sr_person_history');
        });
    }
}
