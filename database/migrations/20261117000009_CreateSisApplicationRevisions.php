<?php

declare(strict_types=1);

use Domain\Admissions\Repository\AdmissionPolicyRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisApplicationRevisions extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException(
                "Application revisions need a database connection.",
            );
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        $existing =
        $sql->rows(
            "sis_access_permissions",
            ["name" => "sis:admissions:amend"],
            1,
        )[0] ?? null;
        if ($existing !== null && (int) $existing["is_system"] !== 1) {
            throw new LogicException(
                "Application amendment authority conflicts with a custom permission.",
            );
        }
        $sql->one("sis_admission_policy", [
            "id" => AdmissionPolicyRepository::ID,
        ]);
        $columns = $this->schema()->getColumns(
            "sis_admission_policy",
            clear: true,
        );
        if (!in_array("answer_mode", $columns, true)) {
            $this->schema()->alter("sis_admission_policy", static function (
                AlterTable $t,
            ): void {
                $t->string("answer_mode", 20)
                    ->notNull()
                    ->defaultValue("request");
            });
        }
        if (!in_array("answer_revision", $columns, true)) {
            $this->schema()->alter("sis_admission_policy", static function (
                AlterTable $t,
            ): void {
                $t->integer("answer_revision")->notNull()->defaultValue(0);
            });
        }
        if (
            !in_array(
                "answer_revision",
                $this->schema()->getColumns("sis_applications", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_applications", static function (
                AlterTable $t,
            ): void {
                // Operational request ordering; formal application versions remain journal-bound.
                $t->integer("answer_revision")->notNull()->defaultValue(0);
            });
        }
        if (
            !$this->schema()->hasTable("sis_application_revisions", clear: true)
        ) {
            $this->schema()->create(
                "sis_application_revisions",
                static function (CreateTable $t): void {
                    $t->string("id", 26)->primary()->notNull();
                    $t->string("application_id", 26)->notNull();
                    $t->string("owner_user_id", 26)->notNull();
                    $t->string("status", 20)->notNull();
                    $t->integer("version")->notNull();
                    $t->text("sealed_data")
                        ->set(name: "size", value: "medium")
                        ->notNull();
                    $t->index(
                        ["application_id", "status", "id"],
                        "sar_application_state",
                    );
                    $t->foreign(
                        "application_id",
                        "sar_application_fk",
                    )->references("sis_applications", "id");
                },
            );
        }
        $required = [
            "id",
            "application_id",
            "owner_user_id",
            "status",
            "version",
            "sealed_data",
        ];
        if (
            array_diff(
                $required,
                $this->schema()->getColumns(
                    "sis_application_revisions",
                    clear: true,
                ),
            ) !== []
        ) {
            throw new LogicException(
                "Review the existing application revision schema before continuing.",
            );
        }
        PortableTransactions::run($db, static function () use ($sql): void {
            $permission =
                $sql->rows(
                    "sis_access_permissions",
                    ["name" => "sis:admissions:amend"],
                    1,
                )[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException(
                    "Application amendment authority conflicts with a custom permission.",
                );
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => "sis:admissions:amend",
                    "description" =>
                        "Request application corrections and assist with answer resubmission.",
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
            // Existing policies, answers and journal rows remain unchanged; no automatic grants.
            $sql->one("sis_admission_policy", [
                "id" => AdmissionPolicyRepository::ID,
            ]);
        });
    }
    public function down(): void
    {
        throw new LogicException(
            "Retain journal-bound answers and correction requests; plan disposition explicitly.",
        );
    }
}
