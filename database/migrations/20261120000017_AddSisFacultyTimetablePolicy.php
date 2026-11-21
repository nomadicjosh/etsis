<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisFacultyTimetablePolicy extends Migration
{
    public function up(): void
    {
        if (
            !in_array(
                "faculty_schedule_mode",
                $this->schema()->getColumns("sis_registration_policy", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_registration_policy", static function (AlterTable $t): void {
                $t->string("faculty_schedule_mode", 8)->notNull()->defaultValue("block");
            });
        }
        if (!$this->hasIndex()) {
            $this->schema()->alter("sis_section_versions", static function (AlterTable $t): void {
                $t->index(
                    ["faculty_user_id", "instruction_starts_on", "instruction_ends_on"],
                    "ssv_faculty_dates_idx",
                );
            });
        }
    }

    private function hasIndex(): bool
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof \Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Faculty timetable indexes require a connection.");
        }
        $pdo = $adapter->connection()->queryBuilder()->getConnection()->pdo;
        $query = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            "mysql"
            => "SELECT 1 FROM information_schema.statistics " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            "pgsql"
            => "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?",
            "sqlite" => "SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
            "sqlsrv" => "SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND name = ?",
            "oci" => "SELECT 1 FROM user_indexes WHERE table_name = ? AND index_name = ?",
            default => throw new LogicException("Unsupported faculty timetable index dialect."),
        };
        $statement = $pdo->prepare($query);
        $statement->execute(["sis_section_versions", "ssv_faculty_dates_idx"]);
        $found = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        return $found;
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve retained timetable policy evidence and existing publication history.",
        );
    }
}
