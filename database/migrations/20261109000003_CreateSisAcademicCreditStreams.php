<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisAcademicCreditStreams extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_academic_credit_streams", true)) {
            $this->schema()->create("sis_academic_credit_streams", static function (CreateTable $t): void {
                $t->string("id", 26)->primary()->notNull();
                $t->string("enrollment_id", 26)->notNull()->unique("sacs_enrollment");
                $t->string("student_id", 26)->notNull();
                $t->string("section_id", 26)->notNull();
                $t->index(["student_id", "id"], "sacs_student");
                // No enrollment/credit FK: early drop removes those current projections, not retained history.
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof \Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Academic permissions require a database connection.");
        }
        $sql = new \Infrastructure\Persistence\SqlRecords($adapter->connection()->queryBuilder());
        \Infrastructure\Persistence\PortableTransactions::run(
            $adapter->connection()->queryBuilder(),
            static function () use ($sql): void {
                $old = $sql->rows("sis_access_permissions", ["name" => "sis:gradebook:amend"], 1)[0] ?? null;
                if ($old !== null) {
                    if ((int) $old["is_system"] !== 1) {
                        throw new LogicException(
                            "The grade-correction permission conflicts with an existing custom definition.",
                        );
                    }
                    return;
                }
                $id = \Domain\Shared\ValueObject\EntityId::generate()->value;
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => "sis:gradebook:amend",
                    "description" => "Approve reasoned final-grade corrections.",
                    "is_system" => 1,
                ]);
                $sql->insert("sis_access_implications", [
                    "id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
                    "ancestor_id" => $id,
                    "descendant_id" => $id,
                ]);
            },
        );
    }
    public function down(): void
    {
        throw new LogicException(
            "Retained academic stream routing must not be discarded while historical events remain.",
        );
    }
}
