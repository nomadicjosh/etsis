<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class AddSisCreditUnitCorrectionPermission extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Credit-unit correction authority requires a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $name = "sis:academic:credits:amend";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Credit-unit correction authority conflicts with a custom permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Approve reasoned institutional credit-unit corrections.",
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
            // Independent duty: do not change existing role/user grants.
        });
    }
    public function down(): void
    {
        throw new LogicException(
            "Retain the protected credit-unit correction permission and its decision references.",
        );
    }
}
