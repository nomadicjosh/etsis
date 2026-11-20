<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdvising extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_student_advisors", clear: true)) {
            $this->schema()->create("sis_student_advisors", static function (CreateTable $t): void {
                $t->string("id", 26)->notNull()->primary();
                $t->string("student_id", 26)->notNull();
                $t->string("pursuit_id", 26)->notNull();
                $t->string("advisor_person_id", 26)->notNull();
                $t->string("role", 16)->notNull();
                $t->string("starts_on", 10)->notNull();
                $t->string("ends_on", 10);
                $t->index(["pursuit_id", "id"], "ssa_pursuit");
                $t->index(["advisor_person_id", "starts_on", "ends_on", "pursuit_id"], "ssa_advisor_dates");
                $t->foreign("student_id", "ssa_student_fk")->references("sis_students", "id");
                $t->foreign("pursuit_id", "ssa_pursuit_fk")->references("sis_student_programs", "id");
                $t->foreign("advisor_person_id", "ssa_person_fk")->references("sis_persons", "id");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Advising needs a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:advising:manage";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting advising permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Review dated student advisor assignments.",
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
        throw new LogicException("Keep dated advising history and its protected permission.");
    }
}
