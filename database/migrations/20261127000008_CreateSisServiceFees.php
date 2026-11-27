<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Migration\NullableUlidReferences;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisServiceFees extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException(
                "Service fees require a database connection.",
            );
        }
        NullableUlidReferences::apply($adapter->connection(), "account_id", [
            "sis_portal_payments",
        ]);
        if (
            !$this->schema()->hasTable("sis_service_fee_policies", clear: true)
        ) {
            $this->schema()->create("sis_service_fee_policies", [
                self::class,
                "definePolicies",
            ]);
        }
        if (!$this->schema()->hasTable("sis_service_fees", clear: true)) {
            $this->schema()->create("sis_service_fees", [
                self::class,
                "defineFees",
            ]);
        }
        $columns = $this->schema()->getColumns(
            "sis_portal_payments",
            clear: true,
        );
        if (!in_array("purpose", $columns, true)) {
            $this->schema()->alter("sis_portal_payments", static function (
                AlterTable $t,
            ): void {
                $t->string("purpose", 16)->notNull()->defaultValue("account");
            });
        }
        if (!in_array("fee_id", $columns, true)) {
            $this->schema()->alter("sis_portal_payments", static function (
                AlterTable $t,
            ): void {
                $t->string("fee_id", 26);
                $t->index(["fee_id", "status", "id"], "spp_fee_status");
            });
        }
        if (
            !in_array(
                "fee_id",
                $this->schema()->getColumns(
                    "sis_transcript_requests",
                    clear: true,
                ),
                true,
            )
        ) {
            $this->schema()->alter("sis_transcript_requests", static function (
                AlterTable $t,
            ): void {
                $t->string("fee_id", 26);
            });
        }
        PortableTransactions::run(
            $adapter->connection()->queryBuilder(),
            static function () use ($adapter): void {
                $sql = new SqlRecords($adapter->connection()->queryBuilder());
                foreach (
                    [
                        "sis:fees:configure" =>
                            "Configure optional application and official transcript fees.",
                        "sis:fees:manage" =>
                            "Review service fee receipts, reasoned waivers and actual refunds.",
                    ] as $name => $description
                ) {
                    $old =
                        $sql->rows(
                            "sis_access_permissions",
                            ["name" => $name],
                            1,
                        )[0] ?? null;
                    if ($old !== null && (int) $old["is_system"] !== 1) {
                        throw new LogicException(
                            "Conflicting service-fee authority.",
                        );
                    }
                    $id = $old["id"] ?? EntityId::generate()->value;
                    if ($old === null) {
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
            },
        );
    }
    public static function definePolicies(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("purpose", 16)->notNull()->unique("ssfp_purpose");
        $t->integer("version")->notNull();
        $t->integer("amount_minor")->notNull();
        $t->string("currency", 3)->notNull();
        $t->boolean("allow_waivers")->notNull();
    }
    public static function defineFees(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->string("person_id", 26)->notNull();
        $t->string("owner_user_id", 36)->notNull();
        $t->string("purpose", 16)->notNull();
        $t->string("target_hash", 64)->notNull();
        $t->integer("version")->notNull();
        $t->string("source_hash", 64)->notNull();
        $t->string("applied_to", 26);
        $t->index(["person_id", "owner_user_id", "id"], "ssf_person_page");
        $t->index(["person_id", "target_hash", "id"], "ssf_target");
        $t->foreign("person_id", "ssf_person_fk")->references(
            "sis_persons",
            "id",
        );
        $t->foreign("owner_user_id", "ssf_owner_fk")->references(
            "users",
            "user_id",
        );
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve assessed fees, actual payments, waivers and their authority.",
        );
    }
}
