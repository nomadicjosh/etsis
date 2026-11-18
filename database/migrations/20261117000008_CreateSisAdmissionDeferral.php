<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisAdmissionDeferral extends Migration
{
    public function up(): void
    {
        $columns = $this->schema()->getColumns('sis_application_intake', clear: true);
        if (!in_array('submitted_term_id', $columns, true)) {
            $this->schema()->alter('sis_application_intake', static function (AlterTable $t): void {
                $t->string('submitted_term_id', 26);
                $t->string('submitted_term_code', 22);
                $t->index(['submitted_term_id', 'id'], 'sai_submitted_term');
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Intake approval permissions require a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        PortableTransactions::run($db, static function () use ($sql): void {
            $p = $sql->rows("sis_access_permissions", ["name" => "sis:admissions:defer"], 1)[0] ?? null;
            if ($p !== null && (int) $p["is_system"] !== 1) {
                throw new LogicException(
                    "The admission-deferral permission conflicts with a custom definition.",
                );
            }
            $id = $p["id"] ?? EntityId::generate()->value;
            if ($p === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => "sis:admissions:defer",
                    "description" => "Approve deferred and changed entry terms before matriculation.",
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
        $codec = $this->getObjectMap()['sis.history_codec'] ?? null;
        new \Infrastructure\EventSourcing\SubmissionProjectionUpgrade(
            $db,
            $codec instanceof \Infrastructure\EventSourcing\ProtectedEventCodec ? $codec : null,
        )->run();
    }
    public function down(): void
    {
        throw new LogicException(
            "Retain recorded intake approvals and their protected permission; plan disposition explicitly.",
        );
    }
}
