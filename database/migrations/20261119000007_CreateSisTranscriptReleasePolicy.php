<?php

declare(strict_types=1);

use Domain\Transcript\Repository\TranscriptReleasePolicyRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisTranscriptReleasePolicy extends Migration
{
    public function up(): void
    {
        if (
            !$this->schema()->hasTable("sis_transcript_policy", clear: true)
        ) {
            $this->schema()->create("sis_transcript_policy", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->text("encrypted_definition")->size("medium");
                $t->integer("revision")->notNull();
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException(
                "Transcript policy requires a database connection.",
            );
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            if (
                $sql->rows(
                    "sis_transcript_policy",
                    ["id" => TranscriptReleasePolicyRepository::POLICY_ID],
                    1,
                ) === []
            ) {
                $sql->insert("sis_transcript_policy", [
                    "id" => TranscriptReleasePolicyRepository::POLICY_ID,
                    "encrypted_definition" => null,
                    "revision" => 0,
                ]);
            }
            $name = "sis:transcript:policy";
            $permission =
                $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ??
                null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException(
                    "The transcript policy permission conflicts with a custom permission.",
                );
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" =>
                        "Configure institutional registration transcript policy.",
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
            // Independent permission: no role/user grants or broad SIS implications.
        });
    }
    public function down(): void
    {
        throw new LogicException(
            "Retain institutional transcript release policy and its accepted confirmations.",
        );
    }
}
