<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;

final class CreateSisCourseTextAssistancePermissions extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Course assistance permissions require a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $permissions = [
                "sis:ai:course:rewrite" => "Request optional rewrites of saved course descriptions.",
                "sis:ai:course:expand" => "Request optional expansion of saved course descriptions.",
            ];
            foreach ($permissions as $name => $description) {
                $existing = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
                if ($existing !== null && (int) $existing["is_system"] !== 1) {
                    throw new LogicException(
                        "An assistance system permission conflicts with a custom definition.",
                    );
                }
                $id = $existing["id"] ?? EntityId::generate()->value;
                if ($existing === null) {
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
                // Independent permissions. No broad implication or role/user grants are added.
            }
        });
    }
    public function down(): void
    {
        throw new LogicException("Retain system permissions; revoke grants through access administration.");
    }
}
