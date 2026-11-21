<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class AddSisGradeCertificationPermission extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Grade certification needs a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:gradebook:certify";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting grade-certification permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Review and certify retained class-wide final grades.",
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
            "Preserve the grade-certification permission and its historical grants.",
        );
    }
}
