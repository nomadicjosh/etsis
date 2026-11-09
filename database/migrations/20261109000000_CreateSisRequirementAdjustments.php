<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisRequirementAdjustments extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_requirement_adjustments", true)) {
            $this->schema()->create("sis_requirement_adjustments", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("assignment_id", 26)->notNull();
                $t->string("scope_key", 191)->notNull();
                $t->text("projection_json")->size("medium")->notNull();
                $t->unique(["assignment_id", "scope_key"], "sra_active_scope");
                $t->foreign("assignment_id", "sra_program_fk")->references("sis_student_programs", "id");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Requirement exception setup requires a connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $name = "sis:degree-audit:adjust";
            $old = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($old !== null) {
                if ((int) $old["is_system"] !== 1) {
                    throw new LogicException(
                        "The requirement-exception permission conflicts with an existing custom definition.",
                    );
                }
                return;
            }
            $id = EntityId::generate()->value;
            $sql->insert("sis_access_permissions", [
                "id" => $id,
                "name" => $name,
                "description" => "Approve and revoke student-program course waivers and minimum exceptions.",
                "is_system" => 1,
            ]);
            $sql->insert("sis_access_implications", [
                "id" => EntityId::generate()->value,
                "ancestor_id" => $id,
                "descendant_id" => $id,
            ]);
        });
    }
    public function down(): void
    {
        $this->schema()->drop("sis_requirement_adjustments");
    }
}
