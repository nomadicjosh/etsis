<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisRecordedDegreeProgress extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_degree_progress_records", clear: true)) {
            $this->schema()->create("sis_degree_progress_records", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("student_id", 26)->notNull();
                $t->string("pursuit_id", 26)->notNull();
                $t->date("recorded_on")->notNull();
                $t->dateTime("recorded_at")->notNull();
                $t->string("source_hash", 64)->notNull();
                $t->index(["student_id", "pursuit_id", "id"], "sdpr_student_page");
                $t->foreign("student_id", "sdpr_student_fk")->references("sis_students", "id");
                $t->foreign("pursuit_id", "sdpr_pursuit_fk")->references(
                    "sis_student_programs",
                    "id",
                );
            });
        }

        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Recorded degree progress need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:degree-audit:record";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting recorded-progress permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Record reviewed degree progress without awarding a degree.",
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
        });
    }

    public function down(): void
    {
        throw new LogicException("Preserve recorded degree progress and its authority.");
    }
}
