<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\ValueObject\EnrollmentLoadPolicyId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisEnrollmentLoadPolicies extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_enrollment_load_policies", clear: true)) {
            $this->schema()->create("sis_enrollment_load_policies", static function (CreateTable $t): void {
                $t->string("id", 26)->notNull()->primary();
                $t->string("program_id", 26);
                $t->integer("position")->notNull();
                $t->string("operation_id", 26);
                $t->text("definition");
                $t->foreign("program_id", "selp_program_fk")->references("sis_programs", "id");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Enrollment load policies need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            if (
                $sql->rows("sis_enrollment_load_policies", ["id" => EnrollmentLoadPolicyId::GLOBAL], 1) === []
            ) {
                $sql->insert("sis_enrollment_load_policies", [
                    "id" => EnrollmentLoadPolicyId::GLOBAL,
                    "program_id" => null,
                    "position" => 0,
                    "operation_id" => null,
                    "definition" => null,
                ]);
            }
            $name = "sis:load:policy";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting enrollment load policy permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Publish institutional and program enrollment-load policies.",
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
        throw new LogicException("Preserve published enrollment-load policies and their authority.");
    }
}
