<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisWaitlists extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_waitlist_state", clear: true)) {
            $this->schema()->create("sis_waitlist_state", static function (CreateTable $t): void {
                $t->string("section_id", 26)->primary()->notNull();
                $t->integer("version")->notNull();
                $t->text("encrypted_state")->notNull();
                $t->foreign("section_id", "swl_section_fk")->references("sis_sections", "id");
            });
        }
        if (!$this->schema()->hasTable("sis_waitlist_entries", clear: true)) {
            $this->schema()->create("sis_waitlist_entries", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("section_id", 26)->notNull();
                $t->string("student_id", 26)->notNull();
                $t->string("person_id", 26)->notNull();
                $t->integer("position")->notNull();
                $t->integer("policy_position")->notNull();
                $t->string("status", 12)->notNull();
                // Accepted enrollment references survive early removal of the enrollment projection.
                $t->string("enrollment_id", 26);
                $t->foreign("section_id", "swle_section_fk")->references("sis_sections", "id");
                $t->foreign("student_id", "swle_student_fk")->references("sis_students", "id");
                $t->index(["section_id", "status", "position"], "swle_section_order");
                $t->index(["student_id", "id"], "swle_student_page");
                $t->unique(["section_id", "position"], "swle_accepted_order");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Waitlisting requires a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:registration:waitlist";
            $row = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($row !== null && (int) $row["is_system"] !== 1) {
                throw new LogicException("Conflicting waitlist permission.");
            }
            $id = $row["id"] ?? EntityId::generate()->value;
            if ($row === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" =>
                        "Review section waitlists and record attributed request removals.",
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
        throw new LogicException("Preserve waitlist decisions and original allocation references.");
    }
}
