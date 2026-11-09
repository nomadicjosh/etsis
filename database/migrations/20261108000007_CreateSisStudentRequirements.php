<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisStudentRequirements extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_student_requirements", true)) {
            $this->schema()->create("sis_student_requirements", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $columns = [
                    "student_id",
                    "program_id",
                    "template_id",
                    "requirements_version_id",
                    "academic_year_id",
                    "cohort_catalog_id",
                    "decision_id",
                ];
                foreach ($columns as $column) {
                    $t->string($column, 26)->notNull();
                }
                $t->integer("cohort_binding_revision")->notNull();
                $t->string("pursuit_started_on", 10)->notNull();
                $t->integer("position")->notNull();
                $t->index(["student_id", "id"], "ssrq_student_read");
                $t->foreign("id", "ssrq_program_fk")->references("sis_student_programs", "id");
                // Reconstructible projection only; authoritative decisions use the shared protected event table.
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Student requirements setup requires a connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $name = "sis:degree-audit:assign";
            $existing = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($existing !== null) {
                if ((int) $existing["is_system"] !== 1) {
                    throw new LogicException(
                        "The degree-assignment system permission conflicts with an existing custom definition.",
                    );
                }
                return;
            }
            $id = EntityId::generate()->value;
            $sql->insert("sis_access_permissions", [
                "id" => $id,
                "name" => $name,
                "description" => "Assign reviewed published degree requirements to student programs.",
                "is_system" => 1,
            ]);
            $sql->insert("sis_access_implications", [
                "id" => EntityId::generate()->value,
                "ancestor_id" => $id,
                "descendant_id" => $id,
            ]);
            // Independent office permission. No role/user grants or existing denials are changed.
        });
    }
    public function down(): void
    {
        $this->schema()->drop("sis_student_requirements");
    }
}
