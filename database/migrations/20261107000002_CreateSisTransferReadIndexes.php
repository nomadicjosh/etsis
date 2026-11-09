<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisTransferReadIndexes extends Migration
{
    private const array INDEXES = [
        ['sis_external_courses', ['institution_id', 'id'], 'sis_external_page'],
        ['sis_transfer_equivalencies', ['external_course_id', 'id'], 'sis_equivalency_page'],
        ['sis_transfer_awards', ['equivalency_id', 'id'], 'sis_transfer_award_page'],
        ['sis_academic_credits', ['student_id', 'term_id', 'id'], 'sis_student_credit_read'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $columns, $name]) {
            $this->schema()->alter($table, static function (AlterTable $definition) use ($columns, $name): void {
                $definition->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Transfer index rollback requires a database connection.');
        }
        $connection = $adapter->connection();
        $driver = $connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (array_reverse(self::INDEXES) as [$table, , $name]) {
            // The installed SQLite/Oracle compilers incorrectly qualify index names with the table.
            if (in_array($driver, ['sqlite', 'oci'], true)) {
                $connection->query('DROP INDEX ' . $connection->quoteIdentifier($name));
                continue;
            }
            $this->schema()->alter($table, static function (AlterTable $definition) use ($name): void {
                $definition->dropIndex($name);
            });
        }
    }
}
