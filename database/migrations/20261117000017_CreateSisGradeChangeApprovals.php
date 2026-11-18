<?php

declare(strict_types=1);

use Domain\AcademicRecord\Repository\GradeChangeRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisGradeChangeApprovals extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_grade_change_policy", clear: true)) {
            $this->schema()->create("sis_grade_change_policy", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("mode", 8)->notNull();
                $t->integer("revision")->notNull();
            });
        }
        if (!$this->schema()->hasTable("sis_grade_change_requests", clear: true)) {
            $this->schema()->create("sis_grade_change_requests", static function (CreateTable $t): void {
                $identities = ["id", "credit_id", "student_id", "section_id", "enrollment_id", "actor_id"];
                foreach ($identities as $field) {
                    $t->string($field, 26)->notNull();
                }
                $t->primary("id");
                $t->string("status", 12)->notNull();
                $t->integer("version")->notNull();
                $t->text("details")->notNull();
                $t->index(["status", "id"], "sgcr_pending");
                $t->index(["credit_id", "status"], "sgcr_credit");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Grade-change policy requires a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use (
            $sql,
        ): void {
            if ($sql->rows("sis_grade_change_policy", ["id" => GradeChangeRepository::POLICY_ID], 1) === []) {
                $sql->insert("sis_grade_change_policy", [
                    "id" => GradeChangeRepository::POLICY_ID,
                    "mode" => "direct",
                    "revision" => 0,
                ]);
            }
            $permissions = [
                "sis:gradebook:approve" =>
                    "Approve or reject another registrar’s requested grade correction.",
                "sis:gradebook:policy" => "Configure institutional final-grade correction approval policy.",
            ];
            foreach ($permissions as $name => $description) {
                $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
                if ($permission !== null && (int) $permission["is_system"] !== 1) {
                    throw new LogicException("A grade-change permission conflicts with a custom permission.");
                }
                $id = $permission["id"] ?? EntityId::generate()->value;
                if ($permission === null) {
                    $sql->insert("sis_access_permissions", [
                        "id" => $id,
                        "name" => $name,
                        "description" => $description,
                        "is_system" => 1,
                    ]);
                }
                if (
                    $sql->rows(
                        "sis_access_implications",
                        ["ancestor_id" => $id, "descendant_id" => $id],
                        1,
                    ) === []
                ) {
                    $sql->insert("sis_access_implications", [
                        "id" => EntityId::generate()->value,
                        "ancestor_id" => $id,
                        "descendant_id" => $id,
                    ]);
                }
            }
            // Independent definitions: no role/user grants or broad SIS implications.
        });
    }
    public function down(): void
    {
        throw new LogicException("Retain grade-change decisions, policy and accepted request references.");
    }
}
