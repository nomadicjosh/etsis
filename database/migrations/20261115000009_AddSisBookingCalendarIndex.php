<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisBookingCalendarIndex extends Migration
{
    public function up(): void
    {
        $this->schema()->alter('sis_booking_slots', static function (AlterTable $t): void {
            $t->index(['cancelled_at', 'ends_at', 'starts_at', 'id'], 'sbs_calendar_window');
        });
    }

    public function down(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('A connection-aware migration adapter is required.');
        }
        $connection = $adapter->connection();
        if (in_array($connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['sqlite', 'oci'], true)) {
            // These engines drop an index by its own name, without the table-name qualifier.
            $connection->command('DROP INDEX ' . $connection->quoteIdentifier('sbs_calendar_window'));
            return;
        }
        $this->schema()->alter('sis_booking_slots', static function (AlterTable $t): void {
            $t->dropIndex('sbs_calendar_window');
        });
    }
}
