<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisAdvisorCalendarIndex extends Migration
{
    public static function define(AlterTable $table): void
    {
        $table->index(['faculty_person_id', 'instruction_starts_on', 'instruction_ends_on'], 'ssv_person_dates');
    }

    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof ConnectionAwareMigrationAdapter) {
            throw new LogicException('Advisor calendar indexes require a database connection.');
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
            default => throw new LogicException('Unsupported advisor calendar index dialect.'),
        };
        $name = $driver === 'pgsql' ? 'sis_section_versions_ssv_person_dates' : 'ssv_person_dates';
        $statement = $pdo->prepare($query);
        $statement->execute(['sis_section_versions', $name]);
        $found = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        if (!$found) {
            $this->schema()->alter('sis_section_versions', self::define(...));
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain the advisor calendar index for bounded published teaching checks.');
    }
}
