<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class CreateSisAdmissionCorrectionPermission extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Admission correction permissions require a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $p = $sql->rows("sis_access_permissions", ["name" => "sis:admissions:correct"], 1)[0] ?? null;
            if ($p !== null && (int) $p["is_system"] !== 1) {
                throw new LogicException(
                    "The admission-correction permission conflicts with a custom definition.",
                );
            }
            $id = $p["id"] ?? EntityId::generate()->value;
            if ($p === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => "sis:admissions:correct",
                    "description" => "Approve reasoned admissions outcome corrections before matriculation.",
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
            // Deliberately no role/user grants and no broad "sis" implication.
        });
    }
    public function down(): void
    {
        throw new LogicException(
            "Retain the protected correction permission; revoke grants through access administration.",
        );
    }
}
