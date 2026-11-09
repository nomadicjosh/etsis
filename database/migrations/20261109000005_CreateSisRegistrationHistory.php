<?php

declare(strict_types=1);

use Qubus\Exception\Exception;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;

final class CreateSisRegistrationHistory extends Migration
{
    /**
     * @throws Exception
     */
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_registration_streams", true)) {
            $this->schema()->create("sis_registration_streams", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("student_id", 26)->notNull();
                $t->string("section_id", 26)->notNull();
                $t->string("term_id", 26)->notNull();
                $t->integer("last_sequence")->notNull();
                $t->index(["student_id", "id"], "srs_student");
                $t->index(["section_id", "last_sequence"], "srs_section");
            });
        }
        if (!$this->schema()->hasTable("sis_registration_decisions", true)) {
            $this->schema()->create("sis_registration_decisions", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("enrollment_id", 26)->notNull();
                $t->string("section_id", 26)->notNull();
                $t->integer("sequence")->notNull();
                $t->integer("stream_version")->notNull();
                $t->unique(["section_id", "sequence"], "srd_section_order");
                $t->unique(["enrollment_id", "stream_version"], "srd_enrollment");
            });
        }
        $this->readIndexes();
    }

    /**
     * @throws Exception
     */
    private function readIndexes(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Registration indexes require a connection.");
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
            default => throw new LogicException("Unsupported registration index dialect."),
        };
        $indexes = [
            ["sis_section_seat_count", ["section_id", "status"]],
            ["sis_student_course_count", ["student_id", "term_id", "status"]],
        ];
        foreach ($indexes as [$name, $columns]) {
            $statement = $pdo->prepare($query);
            $statement->execute(["sis_enrollments", $name]);
            $exists = $statement->fetchColumn() !== false;
            $statement->closeCursor();
            if ($exists) {
                continue;
            }
            $this->schema()->alter("sis_enrollments", static function (AlterTable $t) use (
                $name,
                $columns,
            ): void {
                $t->index($columns, $name);
            });
        }
    }
    public function down(): void
    {
        throw new LogicException(
            "Retained registration routing and decision ordering must not be discarded.",
        );
    }
}
