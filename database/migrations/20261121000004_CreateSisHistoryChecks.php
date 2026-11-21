<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisHistoryChecks extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_history_checks", clear: true)) {
            $this->schema()->create("sis_history_checks", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("subject_type", 8)->notNull();
                $t->string("subject_id", 26)->notNull();
                $t->string("person_id", 26)->notNull();
                $t->string("owner_user_id", 26);
                $t->string("student_id", 26);
                $t->string("actor_id", 26)->notNull();
                $t->string("status", 16)->notNull();
                $t->integer("events_checked")->notNull();
                $t->string("checked_at", 20)->notNull();
                $t->index(["subject_type", "subject_id", "id"], "shc_subject_page");
            });
        }

        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Support checks need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:history:verify";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting support-check permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Run bounded selected-record history checks and read their support receipts.",
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
            "Preserve restricted support receipts until approved retention/disposition.",
        );
    }
}
