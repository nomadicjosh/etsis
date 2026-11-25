<?php

declare(strict_types=1);

use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisAppointmentCalendar extends Migration
{
    public static function define(AlterTable $t): void
    {
        $t->index(['owner_user_id', 'starts_at', 'id'], 'saa_owner_calendar');
    }

    public function up(): void
    {
        if (!$this->hasIndex()) {
            $this->schema()->alter('sis_advising_appointments', self::define(...));
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException('Advising appointments require a database connection.');
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = AdvisingAppointmentService::CORRECT;
            $existing = $sql->rows('sis_access_permissions', ['name' => $name], 1)[0] ?? null;
            if ($existing !== null && (int) $existing['is_system'] !== 1) {
                throw new LogicException('Conflicting appointment correction permission.');
            }
            $id = $existing['id'] ?? EntityId::generate()->value;
            if ($existing === null) {
                $sql->insert('sis_access_permissions', ['id' => $id, 'name' => $name,
                    'description' => 'Correct recorded advising appointment attendance with a reason.',
                        'is_system' => 1]);
            }
            if ($sql->rows('sis_access_implications', ['ancestor_id' => $id, 'descendant_id' => $id], 1) === []) {
                $sql->insert('sis_access_implications', ['id' => EntityId::generate()->value,
                    'ancestor_id' => $id, 'descendant_id' => $id]);
            }
        });
    }

    private function hasIndex(): bool
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException('Appointment calendar indexes require a database connection.');
        }
        $pdo = $adapter->connection()->pdo;
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $query = match ($driver) {
            'mysql' => 'SELECT 1 FROM information_schema.statistics '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            'pgsql' => 'SELECT 1 FROM pg_indexes '
            . 'WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
            'sqlsrv', 'dblib' => 'SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND name = ?',
            'oci' => 'SELECT 1 FROM user_indexes WHERE table_name = ? AND index_name = ?',
            default => throw new LogicException('Unsupported appointment calendar index dialect.'),
        };
        $statement = $pdo->prepare($query);
        $name = $driver === 'pgsql' ? 'sis_advising_appointments_saa_owner_calendar' : 'saa_owner_calendar';
        $statement->execute(['sis_advising_appointments', $name]);
        $found = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        return $found;
    }

    public function down(): void
    {
        throw new LogicException('Preserve appointment facts until institution-approved retention/disposition.');
    }
}
