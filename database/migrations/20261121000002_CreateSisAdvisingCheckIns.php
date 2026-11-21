<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAdvisingCheckIns extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_advising_checkins", clear: true)) {
            $this->schema()->create("sis_advising_checkins", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("student_id", 26)->notNull();
                $t->string("pursuit_id", 26)->notNull();
                $t->string("owner_user_id", 26)->notNull();
                $t->string("status", 12)->notNull();
                $t->integer("version")->notNull();
                $t->text("sealed_data")->size("medium")->notNull();
                $t->index(["student_id", "pursuit_id", "status", "id"], "sac_pursuit_page");
                $t->foreign("student_id", "sac_student_fk")->references("sis_students", "id");
                $t->foreign("pursuit_id", "sac_pursuit_fk")->references(
                    "sis_student_programs",
                    "id",
                );
            });
        }

        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Advising check-ins need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:advising:checkin";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting advising check-in permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Record advising check-ins and manage student follow-up.",
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
        throw new LogicException(
            "Preserve private advising follow-up until approved retention/disposition.",
        );
    }
}
