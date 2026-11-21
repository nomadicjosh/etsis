<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class AddSisTimeReviewPermissions extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_time_policy_state", clear: true)) {
            $this->schema()->create("sis_time_policy_state", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                $t->integer("version")->notNull();
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Time-review policy needs a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            if (
                $sql->rows(
                    "sis_time_policy_state",
                    ["id" => \Domain\HumanResources\TimeApprovalPolicy::ID],
                    1,
                ) === []
            ) {
                $sql->insert("sis_time_policy_state", [
                    "id" => \Domain\HumanResources\TimeApprovalPolicy::ID,
                    "version" => 0,
                ]);
            }

            foreach (
                [
                    "sis:hr:time-policy" => "Publish institutional timesheet review policies.",
                    "sis:hr:time-exception" => "Approve documented timesheet policy exceptions.",
                ] as $name => $description
            ) {
                $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
                if ($permission !== null && (int) $permission["is_system"] !== 1) {
                    throw new LogicException("Conflicting time-review permission.");
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
        throw new LogicException("Preserve time-review permissions and their historical grants.");
    }
}
