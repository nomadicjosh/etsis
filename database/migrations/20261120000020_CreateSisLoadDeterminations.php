<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisLoadDeterminations extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_load_determinations", clear: true)) {
            $this->schema()->create("sis_load_determinations", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("student_id", 26)->notNull();
                $t->string("pursuit_id", 26)->notNull();
                $t->string("term_id", 26)->notNull();
                $t->string("term_code", 191)->notNull();
                $t->string("program_code", 191)->notNull();
                $t->string("program_title", 191)->notNull();
                $t->date("determined_on")->notNull();
                $t->dateTime("recorded_at")->notNull();
                $t->integer("registered_credits_milli")->notNull();
                $t->integer("enrollment_count")->notNull();
                $t->string("classification_code", 32);
                $t->string("classification_title", 120);
                $t->string("policy_version_id", 26)->notNull();
                $t->string("policy_source", 12)->notNull();
                $t->index(["student_id", "pursuit_id", "term_id", "id"], "sld_student_page");
                $t->index(["term_id", "determined_on", "id"], "sld_term_date");
                $t->foreign("student_id", "sld_student_fk")->references("sis_students", "id");
                $t->foreign("pursuit_id", "sld_pursuit_fk")->references(
                    "sis_student_programs",
                    "id",
                );
                $t->foreign("term_id", "sld_term_fk")->references("sis_terms", "id");
            });
        }

        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Load determinations need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:load:determine";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting load-determination permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" =>
                        "Record current enrollment-load determinations from verified registrations.",
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
        throw new LogicException("Preserve retained load determinations and their authority.");
    }
}
