<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPrerequisitePermissions extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_prerequisite_permissions", clear: true)) {
            $this->schema()->create("sis_prerequisite_permissions", static function (CreateTable $t): void {
                $t->string("id", 26)->notNull()->primary();
                $t->string("student_id", 26)->notNull();
                $t->string("pursuit_id", 26)->notNull();
                $t->string("section_id", 26)->notNull();
                $t->integer("position")->notNull();
                $t->string("operation_id", 26)->notNull();
                $t->text("approval");
                $t->unique(["pursuit_id", "section_id"], "spp_scope_uq");
                $t->foreign("student_id", "spp_student_fk")->references("sis_students", "id");
                $t->foreign("pursuit_id", "spp_pursuit_fk")->references("sis_student_programs", "id");
                $t->foreign("section_id", "spp_section_fk")->references("sis_sections", "id");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Prerequisite permissions need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:registration:prerequisite-exception";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting prerequisite exception permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Approve and revoke pursuit-scoped prerequisite exceptions.",
                    "is_system" => 1,
                ]);
            }
            if (
                $sql->rows("sis_access_implications", ["ancestor_id" => $id, "descendant_id" => $id], 1) ===
                []
            ) {
                $sql->insert("sis_access_implications", [
                    "id" => EntityId::generate()->value,
                    "ancestor_id" => $id,
                    "descendant_id" => $id,
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException("Preserve recorded prerequisite exceptions and their authority.");
    }
}
