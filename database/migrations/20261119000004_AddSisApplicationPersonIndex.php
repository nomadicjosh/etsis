<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisApplicationPersonIndex extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_applications', static function (AlterTable $table): void {
            $table->index(['person_id', 'id'], 'sa_person_report');
        });
    }

    public function down(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Index rollback requires a database connection.');
        }
        $connection = $adapter->connection();
        $driver = $connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            // MySQL may replace its implicit FK index when this wider index is added.
            // Restore FK support before dropping the report index, without disabling constraints.
            $statement = $connection->pdo->prepare(
                'SELECT 1 FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1 AND INDEX_NAME <> ?'
            );
            $statement->execute(['sis_applications', 'person_id', 'sa_person_report']);
            $supported = $statement->fetchColumn() !== false;
            $statement->closeCursor();
            if (!$supported) {
                $this->schema()->alter('sis_applications', static function (AlterTable $table): void {
                    $table->index(['person_id'], 'sis_appl_person_fk');
                });
            }
        }
        // Installed SQLite/Oracle compilers incorrectly qualify the index with its table.
        if (in_array($driver, ['sqlite', 'oci'], true)) {
            $connection->query('DROP INDEX ' . $connection->quoteIdentifier('sa_person_report'));
            return;
        }
        $this->schema()->alter('sis_applications', static function (AlterTable $table): void {
            $table->dropIndex('sa_person_report');
        });
    }
}
