<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentSpecializationRepository;
use Domain\Student\ValueObject\StudentSpecializationSchema;
use Domain\Student\ValueObject\StudentSpecializationRequirementsSchema;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStudentSpecializationRepository implements StudentSpecializationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function get(EntityId $assignment): ?array
    {
        $value = $this->selection($assignment);
        if ($value === null) {
            if (
                $this->sql->rows(
                    "sis_student_curriculum_components",
                    ["assignment_id" => $assignment->value],
                    1,
                ) !== []
            ) {
                throw new CorruptHistoryException();
            }
            return null;
        }
        $row = $this->sql->rows("sis_student_specializations", ["id" => $assignment->value], 1)[0];
        $expected = $this->componentRows($value["payload"], $value["position"]);
        $count = filter_var($row["component_count"], FILTER_VALIDATE_INT);
        if (
            $count === false ||
            $count !== count($expected) ||
            $this->components($assignment) !== $expected
        ) {
            throw new CorruptHistoryException();
        }
        return $value;
    }
    private function selection(EntityId $assignment): ?array
    {
        $row =
        $this->sql->rows("sis_student_specializations", ["id" => $assignment->value], 1)[0] ??
        null;
        if ($row === null) {
            return null;
        }
        try {
            $position = filter_var($row["stream_version"], FILTER_VALIDATE_INT);
            $revision = filter_var($row["cohort_binding_revision"], FILTER_VALIDATE_INT);
            if ($position === false || $position < 1 || $revision === false) {
                throw new CorruptHistoryException();
            }
            $payload = $row;
            unset(
                $payload["id"],
                $payload["stream_version"],
                $payload["selected_json"],
                $payload["component_count"],
            );
            $payload["assignment_id"] = $assignment->value;
            $payload["cohort_binding_revision"] = $revision;
            $payload["specialization_ids"] = json_decode(
                $row["selected_json"],
                true,
                4,
                JSON_THROW_ON_ERROR,
            );
            return [
                "payload" => StudentSpecializationSchema::read($payload),
                "position" => $position,
            ];
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function project(EntityId $assignment, array $payload, int $position): void
    {
        $payload = StudentSpecializationSchema::read($payload);
        if ($payload["assignment_id"] !== $assignment->value || $position < 1) {
            throw new CorruptHistoryException();
        }
        $row = $payload;
        unset($row["assignment_id"], $row["specialization_ids"]);
        $components = $this->componentRows($payload, $position);
        $row["component_count"] = count($components);
        $row["stream_version"] = $position;
        $row["selected_json"] = json_encode($payload["specialization_ids"], JSON_THROW_ON_ERROR);
        if (
            $this->sql->rows("sis_student_specializations", ["id" => $assignment->value], 1) === []
        ) {
            $this->sql->insert("sis_student_specializations", ["id" => $assignment->value] + $row);
        } else {
            $this->sql->update("sis_student_specializations", $row, ["id" => $assignment->value]);
        }
        $this->sql->execute(
            "DELETE FROM sis_student_curriculum_components WHERE assignment_id = ?",
            [$assignment->value],
        );
        foreach ($components as $component) {
            $this->sql->insert("sis_student_curriculum_components", $component);
        }
    }
    /** Migration/recovery only: validate the actual retained streams before rebuilding this derived index. */
    public function rebuildComponents(
        EntityId $assignment,
        \Domain\Shared\EventSourcing\HistoricalEventStore $history,
        \Domain\Shared\EventSourcing\HistoryReadBudget $budget,
    ): void {
        $root = \Domain\Student\StudentSpecializations::root(
            new \Domain\Student\ValueObject\StudentSpecializationsId($assignment->value),
        );
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            $root->aggregateId(),
            "student_specializations",
        );
        $cursor = null;
        do {
            $page = $budget->page($history, $stream, $cursor);
            foreach ($page->events as $event) {
                $root->acceptHistorical($event->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $payload = $root->selection() ?? throw new CorruptHistoryException();
        if (
            $this->selection($assignment) !== [
                "payload" => $payload,
                "position" => $root->playhead(),
            ] ||
            $this->requirements($assignment) !== $root->requirements()
        ) {
            throw new CorruptHistoryException();
        }
        $reader = new \Domain\Catalog\Service\PublishedProgramReader(
            $history,
            new SqlProgramDefinitionRepository($this->sql),
        );
        $reader->version(
            new EntityId($payload["program_id"]),
            new EntityId($payload["academic_year_id"]),
            new EntityId($payload["program_version_id"]),
            $budget,
        ) ?? throw new CorruptHistoryException();
        $this->project($assignment, $payload, $root->playhead());
        $this->get($assignment);
    }
    private function componentRows(array $payload, int $position): array
    {
        $program =
        new SqlProgramDefinitionRepository($this->sql)->retained(
            new EntityId($payload["program_version_id"]),
        ) ?? throw new CorruptHistoryException();
        return \Domain\Student\ValueObject\SelectedCurriculumRows::from(
            $payload,
            $program,
            $position,
        );
    }
    private function components(EntityId $assignment): array
    {
        $rows = $this->sql->rows(
            "sis_student_curriculum_components",
            ["assignment_id" => $assignment->value],
            101,
        );
        if (count($rows) > 100) {
            throw new CorruptHistoryException();
        }
        foreach ($rows as &$row) {
            foreach (
                [
                    "selection_position",
                    "cohort_binding_revision",
                    "catalog_revision",
                    "kind_recorded",
                    "department_recorded",
                    "parent_major_recorded",
                ] as $field
            ) {
                $integer = filter_var($row[$field], FILTER_VALIDATE_INT);
                if ($integer === false) {
                    throw new CorruptHistoryException();
                }
                $row[$field] = $integer;
            }
            ksort($row);
        }
        unset($row);
        usort($rows, static fn(array $a, array $b): int => strcmp($a["id"], $b["id"]));
        return $rows;
    }
    public function requirements(EntityId $assignment): array
    {
        try {
            $rows = $this->sql->rows(
                "sis_student_specialization_requirements",
                ["assignment_id" => $assignment->value],
                101,
            );
            if (count($rows) > 100) {
                throw new CorruptHistoryException();
            }
            $out = [];
            foreach ($rows as $row) {
                $p = StudentSpecializationRequirementsSchema::read(
                    json_decode($row["reference_json"], true, 4, JSON_THROW_ON_ERROR),
                );
                if (
                    $p["assignment_id"] !== $assignment->value ||
                    $p["decision_id"] !== $row["id"] ||
                    $p["specialization_id"] !== $row["specialization_id"] ||
                    isset($out[$p["specialization_id"]])
                ) {
                    throw new CorruptHistoryException();
                }
                $out[$p["specialization_id"]] = $p;
            }
            ksort($out, SORT_STRING);
            return $out;
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function projectRequirements(EntityId $assignment, array $requirements): void
    {
        if (count($requirements) > 100) {
            throw new CorruptHistoryException();
        }
        foreach ($requirements as $specialization => $payload) {
            $p = StudentSpecializationRequirementsSchema::read($payload);
            if (
                $p["assignment_id"] !== $assignment->value ||
                $p["specialization_id"] !== $specialization
            ) {
                throw new CorruptHistoryException();
            }
            $requirements[$specialization] = $p;
        }
        $before = $this->requirements($assignment);
        foreach ($before as $specialization => $p) {
            if (!isset($requirements[$specialization])) {
                $this->sql->execute(
                    "DELETE FROM sis_student_specialization_requirements WHERE id = ? AND assignment_id = ?",
                    [$p["decision_id"], $assignment->value],
                );
            }
        }
        foreach ($requirements as $specialization => $p) {
            if (($before[$specialization] ?? null) === $p) {
                continue;
            }
            $row = [
                "id" => $p["decision_id"],
                "assignment_id" => $assignment->value,
                "specialization_id" => $specialization,
                "reference_json" => json_encode($p, JSON_THROW_ON_ERROR),
            ];
            if (isset($before[$specialization])) {
                $this->sql->update("sis_student_specialization_requirements", $row, [
                    "assignment_id" => $assignment->value,
                    "specialization_id" => $specialization,
                ]);
            } else {
                $this->sql->insert("sis_student_specialization_requirements", $row);
            }
        }
    }
}
