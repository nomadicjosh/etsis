<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisGraduation extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_graduation_cases", clear: true)) {
            $this->schema()->create("sis_graduation_cases", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("student_id", 26)->notNull();
                $t->integer("version")->notNull();
                $t->string("source_hash", 64)->notNull();
                $t->integer("eligible")->notNull()->defaultValue(0);
                $t->index(["eligible", "id"], "sgc_eligible_page");
                $t->foreign("student_id", "sgc_student_fk")->references(
                    "sis_students",
                    "id",
                );
                $t->foreign("id", "sgc_pursuit_fk")->references(
                    "sis_student_programs",
                    "id",
                );
            });
        }

        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException(
                "Graduation decisions need a database connection.",
            );
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            foreach (
                [
                    "sis:graduation:review" =>
                        "Record reviewed graduation eligibility.",
                    "sis:graduation:confer" =>
                        "Confer, correct or revoke academic credentials.",
                ] as $name => $description
            ) {
                $permission =
                    $sql->rows(
                        "sis_access_permissions",
                        ["name" => $name],
                        1,
                    )[0] ?? null;
                if (
                    $permission !== null &&
                    (int) $permission["is_system"] !== 1
                ) {
                    throw new LogicException(
                        "Conflicting graduation permission.",
                    );
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
        throw new LogicException(
            "Preserve graduation records and their authority.",
        );
    }
}
