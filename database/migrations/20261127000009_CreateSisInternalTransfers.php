<?php

declare(strict_types=1);

use Infrastructure\Persistence\Migration\PortableView;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Connection;

final class CreateSisInternalTransfers extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException(
                "Internal transfer decisions require a connection.",
            );
        }
        if (
            !$this->schema()->hasTable(
                "sis_internal_transfer_awards",
                clear: true,
            )
        ) {
            $this->schema()->create("sis_internal_transfer_awards", [
                self::class,
                "define",
            ]);
        }
        $connection = $adapter->connection();
        if (!PortableView::exists($connection, "sis_transfer_sources")) {
            $connection->command(self::viewSql($connection));
        }
    }
    public static function define(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        foreach (
            [
                "student_id",
                "credit_id",
                "term_id",
                "source_credit_id",
                "source_pursuit_id",
                "source_credential_id",
                "receiving_assignment_id",
            ] as $name
        ) {
            $t->string($name, 26)->notNull();
        }
        $t->string("completed_on", 10)->notNull();
        $t->string("lineage_hash", 64)->notNull();
        $t->unique(
            ["receiving_assignment_id", "source_credit_id"],
            "sita_source_once",
        );
        $t->index(
            ["student_id", "receiving_assignment_id", "id"],
            "sita_pursuit_page",
        );
        $t->foreign("student_id", "sita_student_fk")->references(
            "sis_students",
            "id",
        );
        $t->foreign("credit_id", "sita_credit_fk")->references(
            "sis_academic_credits",
            "id",
        );
        $t->foreign("source_credit_id", "sita_source_fk")->references(
            "sis_academic_credits",
            "id",
        );
        $t->foreign("receiving_assignment_id", "sita_receiving_fk")->references(
            "sis_student_programs",
            "id",
        );
        $t->foreign("source_pursuit_id", "sita_pursuit_fk")->references(
            "sis_student_programs",
            "id",
        );
        $t->foreign("term_id", "sita_term_fk")->references("sis_terms", "id");
    }
    public static function viewSql(Connection $connection): string
    {
        $q = $connection->quoteIdentifier(...);
        $common = implode(
            ", ",
            array_map($q, [
                "id",
                "student_id",
                "credit_id",
                "term_id",
                "completed_on",
            ]),
        );
        return "CREATE VIEW " .
        $q("sis_transfer_sources") .
        " AS SELECT " .
        $common .
        ", " .
        $q("equivalency_id") .
        ", " .
        $q("corrected_completed_on") .
        ", 'external' AS " .
        $q("source_kind") .
        " FROM " .
        $q("sis_transfer_awards") .
        " UNION ALL SELECT " .
        $common .
        ", CAST(NULL AS VARCHAR(26)) AS " .
        $q("equivalency_id") .
        ", CAST(NULL AS VARCHAR(10)) AS " .
        $q("corrected_completed_on") .
        ", 'same_institution' AS " .
        $q("source_kind") .
        " FROM " .
        $q("sis_internal_transfer_awards");
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve receiving transfer decisions and original lineage.",
        );
    }
}
