<?php

declare(strict_types=1);

use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Infrastructure\Persistence\Repository\SqlStudentSpecializationRepository;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCurriculumReports extends Migration
{
    public function up(): void
    {
        if (!$this->schema()->hasTable("sis_student_curriculum_components", clear: true)) {
            $this->schema()->create("sis_student_curriculum_components", static function (
                CreateTable $t,
            ): void {
                $t->string("id", 26)->primary()->notNull();
                foreach (
                    [
                        "assignment_id",
                        "student_id",
                        "program_id",
                        "program_version_id",
                        "academic_year_id",
                        "selection_decision_id",
                        "component_id",
                    ] as $field
                ) {
                    $t->string($field, 26)->notNull();
                }
                foreach (
                    ["selection_position", "cohort_binding_revision", "catalog_revision"] as $field
                ) {
                    $t->integer($field)->notNull();
                }
                foreach (["program_code", "academic_year_code", "component_code"] as $field) {
                    $t->string($field, 80)->notNull();
                }
                foreach (["program_title", "component_title"] as $field) {
                    $t->string($field, 191)->notNull();
                }
                $t->string("component_kind", 24);
                foreach (
                    ["kind_recorded", "department_recorded", "parent_major_recorded"] as $field
                ) {
                    $t->integer($field)->size('tiny')->notNull();
                }
                foreach (["department", "school", "parent_major"] as $prefix) {
                    $t->string($prefix . "_id", 26);
                    $t->string($prefix . "_code", 80);
                    $t->string($prefix . "_title", 191);
                }
                $t->unique(["assignment_id", "component_id"], "sccr_choice_uq");
                $t->index(["student_id", "assignment_id"], "sccr_student_idx");
                $t->index(["component_kind", "department_code"], "sccr_owner_idx");
                $t->foreign("assignment_id", "sccr_selection_fk")->references(
                    "sis_student_specializations",
                    "id",
                );
            });
        }
        if (
            !in_array(
                "component_count",
                $this->schema()->getColumns("sis_student_specializations", clear: true),
                true,
            )
        ) {
            $this->schema()->alter("sis_student_specializations", static function (
                AlterTable $t,
            ): void {
                $t->integer("component_count");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Curriculum reports need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        $sql = new SqlRecords($db);
        $pending =
        "SELECT id, student_id FROM sis_student_specializations WHERE component_count IS NULL ORDER BY id";
        $rows = $sql->fetch($pending, [], 21);
        if ($rows === []) {
            return;
        }
        $history =
        $this->getObjectMap()[HistoricalEventStore::class] ??
        new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $sql,
            new \Infrastructure\Persistence\DatabaseUnitOfWork($db),
            new \Infrastructure\SystemClock(),
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                \Codefy\Framework\Helpers\config(),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        PortableTransactions::run($db, static function () use ($sql, $history, $rows): void {
            $budget = new HistoryReadBudget();
            $students = new SqlStudentRecordRepository($sql);
            $records = new SqlStudentSpecializationRepository($sql);
            $locks = array_unique(array_column(array_slice($rows, 0, 20), "student_id"));
            sort($locks, SORT_STRING);
            foreach ($locks as $id) {
                $students->lock(new EntityId($id));
            }
            foreach (array_slice($rows, 0, 20) as $row) {
                $current = $sql->one("sis_student_specializations", ["id" => $row["id"]]);
                if ($current["student_id"] !== $row["student_id"]) {
                    throw new LogicException(
                        "The curriculum selection changed while awaiting its lock. Retry the migration.",
                    );
                }
                $records->rebuildComponents(new EntityId($row["id"]), $history, $budget);
            }
        });
        if ($sql->fetch($pending, [], 1) !== []) {
            throw new LogicException(
                "One bounded curriculum projection batch completed. Retry the migration; " .
                "reports remain unavailable until verification completes.",
            );
        }
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve selected curriculum facts; rebuild reporting only from verified history.",
        );
    }
}
