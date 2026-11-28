<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPersonPhotos extends Migration
{
    public function up(): void
    {
        $tables = ["sis_photo_files" => "files", "sis_photo_payloads" => "payloads"];
        foreach ($tables as $name => $method) {
            if (!$this->schema()->hasTable($name, clear: true)) {
                $this->schema()->create($name, [self::class, $method]);
            }
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException(
                "Photo setup requires a database connection.",
            );
        }
        $sql = new SqlRecords($adapter->connection()->queryBuilder());
        $name = "sis:person:photo:manage";
        $old =
        $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ??
        null;
        if ($old !== null && (int) $old["is_system"] !== 1) {
            throw new LogicException(
                "Conflicting institutional-photo permission.",
            );
        }
        $id = $old["id"] ?? EntityId::generate()->value;
        if ($old === null) {
            $sql->insert("sis_access_permissions", [
                "id" => $id,
                "name" => $name,
                "is_system" => 1,
                "description" =>
                    "Review and correct institution-managed person photos and approved photo imports.",
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

    public static function files(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("person_id", 26);
        $t->string("actor_id", 26)->notNull();
        $t->string("state", 12)->notNull();
        $t->string("expires_at", 30);
        $t->text("encrypted_manifest")->size("medium")->notNull();
        $t->index(["expires_at", "id"], "photo_disposition_due");
        $t->index(["person_id", "state"], "photo_person_files");
    }

    public static function payloads(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("artifact_id", 26)->notNull();
        $t->integer("part")->notNull();
        $t->text("encrypted_content")->size("medium")->notNull();
        $t->unique(["artifact_id", "part"], "photo_payload_order");
        $t->foreign("artifact_id", "photo_payload_file")->references(
            "sis_photo_files",
            "id",
        );
    }

    public function down(): void
    {
        throw new LogicException(
            "Preserve protected photo choices and apply reviewed artifact retention.",
        );
    }
}
