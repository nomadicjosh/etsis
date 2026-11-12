<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisMoodlePersonLinks extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Moodle person links require a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        $permission =
            $sql->rows("sis_access_permissions", ["name" => "sis:moodle:link-person"], 1)[0] ?? null;
        if ($permission !== null && (int) $permission["is_system"] !== 1) {
            throw new LogicException("A Moodle person-link permission conflicts with a custom definition.");
        }
        $this->schema()->create("sis_moodle_person_links", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("binding_id", 26)->notNull();
            $table->string("person_id", 26)->notNull();
            $table->string("moodle_user_id", 19)->notNull();
            $table->string("approved_by", 26)->notNull();
            $table->string("approved_at", 32)->notNull();
            $table->text("encrypted_review")->notNull();
            $table->unique(["binding_id", "person_id"], "moodle_link_person_uq");
            $table->unique(["binding_id", "moodle_user_id"], "moodle_link_external_uq");
            $table->foreign("binding_id", "moodle_link_binding_fk")->references("sis_moodle_bindings", "id");
            $table->foreign("person_id", "moodle_link_person_fk")->references("sis_persons", "id");
        });
        PortableTransactions::run($db, static function () use ($sql, $permission): void {
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => "sis:moodle:link-person",
                    "description" => "Review and approve explicit person-to-Moodle user links.",
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
            // No role grants or broad permission implications are added.
        });
    }

    public function down(): void
    {
        throw new LogicException("Retain reviewed person links and their protected permission.");
    }
}
