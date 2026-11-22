<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisPrimaryPositions extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_primary_positions", clear: true)) {
            $this->schema()->create("sis_primary_positions", static function (
                CreateTable $t,
            ): void {
                $t->string("employee_id", 26)->primary()->notNull();
                $t->text("encrypted_designation")->notNull();
                $t->integer("version")->notNull();
                $t->foreign("employee_id", "spp_employee_fk")->references("sis_employees", "id");
            });
        }

        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Primary positions need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:hr:primary";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting primary-position permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Designate an employee primary position with HR authority.",
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
        throw new LogicException("Preserve primary-position history and its authority.");
    }
}
