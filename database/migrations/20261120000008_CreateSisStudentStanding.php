<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisStudentStanding extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_standing_definitions", clear: true)) {
            $this->schema()->create("sis_standing_definitions", static function (CreateTable $t): void {
                $t->string("id", 26)->notNull()->primary();
                $t->string("code", 32)->notNull()->unique("ssd_code");
                $t->string("title", 120)->notNull();
                $t->integer("active")->notNull()->defaultValue(1);
                $t->integer("version")->notNull()->defaultValue(1);
            });
        }
        if (!$this->schema()->hasTable("sis_student_standing", clear: true)) {
            $this->schema()->create("sis_student_standing", static function (CreateTable $t): void {
                $t->string("id", 26)->notNull()->primary();
                $t->string("student_id", 26)->notNull();
                $t->string("pursuit_id", 26)->notNull();
                $t->string("definition_id", 26)->notNull();
                $t->string("code", 32)->notNull();
                $t->string("title", 120)->notNull();
                $t->string("starts_on", 10)->notNull();
                $t->string("ends_on", 10);
                $t->string("policy_reference", 200);
                $t->index(["pursuit_id", "id"], "ss_pursuit");
                $t->index(["student_id", "starts_on", "ends_on"], "ss_student_dates");
                $t->foreign("student_id", "ss_student_fk")->references("sis_students", "id");
                $t->foreign("pursuit_id", "ss_pursuit_fk")->references("sis_student_programs", "id");
                $t->foreign("definition_id", "ss_definition_fk")->references(
                    "sis_standing_definitions",
                    "id",
                );
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Standing needs a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            foreach (
                [
                    "sis:standing:configure" => "Maintain institutional academic standing labels.",
                    "sis:standing:manage" => "Review dated student academic standing decisions.",
                ] as $name => $description
            ) {
                $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
                if ($permission !== null && (int) $permission["is_system"] !== 1) {
                    throw new LogicException("Conflicting academic standing permission.");
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
        });
    }

    public function down(): void
    {
        throw new LogicException("Preserve recorded academic standing and its protected definitions.");
    }
}
