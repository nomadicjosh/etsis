<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisMoodleAccountRequests extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Moodle account requests require a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        $permission =
            $sql->rows("sis_access_permissions", ["name" => "sis:moodle:create-person"], 1)[0] ?? null;
        if ($permission !== null && (int) $permission["is_system"] !== 1) {
            throw new LogicException(
                "A Moodle account-request permission conflicts with a custom definition.",
            );
        }
        $this->schema()->create("sis_moodle_account_requests", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("binding_id", 26)->notNull();
            $table->string("person_id", 26)->notNull();
            $table->string("requested_by", 26)->notNull();
            $table->string("requested_at", 32)->notNull();
            $table->string("review_hash", 64)->notNull();
            $table->text("encrypted_approval")->notNull();
            $table->unique(["binding_id", "person_id"], "moodle_account_person_uq");
            $table
                ->foreign("binding_id", "moodle_account_binding_fk")
                ->references("sis_moodle_bindings", "id");
            $table->foreign("person_id", "moodle_account_person_fk")->references("sis_persons", "id");
        });
        $this->schema()->create("sis_moodle_provisioning_outbox", static function (CreateTable $table): void {
            $table->string("id", 26)->primary()->notNull();
            $table->string("request_id", 26)->unique("moodle_outbox_request_uq")->notNull();
            $table->string("state", 20)->notNull();
            $table->integer("version")->notNull();
            $table->string("created_at", 32)->notNull();
            $table->index(["state", "id"], "moodle_outbox_state_idx");
            $table
                ->foreign("request_id", "moodle_outbox_request_fk")
                ->references("sis_moodle_account_requests", "id");
        });
        PortableTransactions::run($db, static function () use ($sql, $permission): void {
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => "sis:moodle:create-person",
                    "description" => "Review and request Moodle account creation for a person.",
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
        throw new LogicException("Retain reviewed account requests and provisioning references.");
    }
}
