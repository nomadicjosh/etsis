<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisVersionedGrades extends Migration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Grade consolidation requires a database connection.");
        }
        $connection = $adapter->connection();
        new \Infrastructure\Persistence\GradeDefinitionSchemaUpgrade($connection)->run();
        $codec = $this->getObjectMap()["sis.history_codec"] ?? null;
        new \Infrastructure\EventSourcing\GradeDefinitionProjectionUpgrade(
            $connection->queryBuilder(),
            $codec instanceof \Infrastructure\EventSourcing\ProtectedEventCodec ? $codec : null,
        )->run();
        $columns = $this->schema()->getColumns("sis_grading_scales", true);
        if (is_array($columns) && in_array("definition_json", $columns, true)) {
            $this->schema()->alter("sis_grading_scales", static function (AlterTable $t): void {
                $t->dropColumn("definition_json");
            });
        }
    }
    public function down(): void
    {
        throw new LogicException(
            "Published grade identities cannot be collapsed into a single mutable code. " .
                "Restore a verified backup for a database rollback.",
        );
    }
}
