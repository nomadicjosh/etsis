<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use Domain\Gradebook\ValueObject\CourseworkGradePolicy;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\AssignmentScore;
use Domain\Gradebook\Repository\GradebookRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlGradebookRepository implements GradebookRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function calculation(EntityId $section): array
    {
        $row = $this->sql->rows("sis_gradebook_calculations", ["id" => $section->value], 1)[0] ?? null;
        if ($row === null) {
            return [
                "mode" => "points",
                "weights" => [],
                "revision" => 0,
                "categories" => [],
                "grade_policy" => null,
            ];
        }
        return [
            "grade_policy" => $row['grade_policy_json'] === null ? null : new CourseworkGradePolicy(
                json_decode($row['grade_policy_json'], true, 4, JSON_THROW_ON_ERROR),
            )->facts,
            "mode" => $row["mode"],
            "weights" => json_decode($row["weights"], true, 4, JSON_THROW_ON_ERROR),
            "revision" => (int) $row["revision"],
            "categories" =>
                $row["categories"] === null
                    ? []
                    : json_decode($row["categories"], true, 6, JSON_THROW_ON_ERROR),
        ];
    }

    public function saveCalculation(
        EntityId $section,
        string $mode,
        array $weights,
        int $revision,
        array $categories,
        ?array $gradePolicy,
    ): void {
        $data = [
            "grade_policy_json" => $gradePolicy === null ? null : json_encode($gradePolicy, JSON_THROW_ON_ERROR),
            "mode" => $mode,
            "weights" => json_encode($weights, JSON_THROW_ON_ERROR),
            "revision" => $revision + 1,
            "categories" => json_encode($categories, JSON_THROW_ON_ERROR),
        ];
        if ($revision === 0) {
            $this->sql->insert("sis_gradebook_calculations", ["id" => $section->value] + $data);
        } elseif (
            $this->sql->update("sis_gradebook_calculations", $data, [
                "id" => $section->value,
                "revision" => $revision,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("Reload the gradebook setup before saving.");
        }
    }

    public function assignments(EntityId $section, int $limit): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_assignments") .
                " WHERE " .
                $q("section_id") .
                " = ? ORDER BY " .
                $q("id"),
            [$section->value],
            $limit,
        );
        foreach ($rows as &$row) {
            $row["possible_hundredths"] = (int) $row["possible_hundredths"];
            $row["bonus_hundredths"] = (int) $row["bonus_hundredths"];
        }
        unset($row);
        return $rows;
    }

    public function bonusAssignmentIds(EntityId $section, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if (count($ids) > \Domain\Gradebook\ValueObject\GradebookCalculation::LIMIT) {
            throw new \InvalidArgumentException("Use at most 500 configured assignment identities.");
        }
        $q = $this->sql->quote(...);
        return array_column($this->sql->fetch(
            "SELECT " . $q("id") . " FROM " . $q("sis_assignments") .
                " WHERE " . $q("section_id") . " = ? AND " . $q("possible_hundredths") .
                " = 0 AND " . $q("id") . " IN (" . implode(",", array_fill(0, count($ids), "?")) . ")",
            [$section->value, ...$ids],
            count($ids),
        ), "id");
    }

    public function addAssignment(Assignment $assignment): void
    {
        $this->sql->insert("sis_assignments", [
            "id" => $assignment->id->value,
            "section_id" => $assignment->sectionId->value,
            "title" => $assignment->title,
            "possible_hundredths" => $assignment->possibleHundredths,
            "bonus_hundredths" => $assignment->bonusHundredths,
            "due_on" => $assignment->dueOn->format("Y-m-d"),
        ]);
    }

    public function assignment(EntityId $id): Assignment
    {
        $r = $this->sql->one("sis_assignments", ["id" => $id->value]);

        return new Assignment(
            $id,
            new EntityId($r["section_id"]),
            $r["title"],
            (int) $r["possible_hundredths"],
            new DateTimeImmutable($r["due_on"]),
            (int) $r["bonus_hundredths"],
        );
    }

    public function saveAssignment(Assignment $assignment): void
    {
        $this->sql->update(
            "sis_assignments",
            [
                "title" => $assignment->title,
                "possible_hundredths" => $assignment->possibleHundredths,
                "bonus_hundredths" => $assignment->bonusHundredths,
                "due_on" => $assignment->dueOn->format("Y-m-d"),
            ],
            [
                "id" => $assignment->id->value,
                "section_id" => $assignment->sectionId->value,
            ],
        );
    }

    public function highestScore(EntityId $assignment): ?int
    {
        $q = $this->sql->quote(...);
        $row = $this->sql->fetch(
            "SELECT MAX(" .
                $q("earned_hundredths") .
                ") AS " .
                $q("maximum") .
                " FROM " .
                $q("sis_scores") .
                " WHERE " .
                $q("assignment_id") .
                " = ?",
            [$assignment->value],
        )[0];

        return $row["maximum"] === null ? null : (int) $row["maximum"];
    }

    public function scoreRoster(
        Assignment $assignment,
        string $search,
        string $state,
        ?EntityId $after,
        int $limit,
    ): array {
        $q = $this->sql->quote(...);
        $col = static fn(string $alias, string $field): string => $alias . "." . $q($field);
        $condition = match ($state) {
            "all" => "1 = 1",
            "excused" => $col("m", "excused") . " = 1",
            "pending" => $col("m", "earned_hundredths") .
            " IS NULL AND COALESCE(" .
            $col("m", "excused") .
            ", 0) = 0",
            "scored" => $col("m", "earned_hundredths") .
            " IS NOT NULL AND COALESCE(" .
            $col("m", "excused") .
            ", 0) = 0",
        };
        $rows = $this->worksheetRoster(
            $assignment->sectionId,
            $search,
            $after,
            $limit,
            "sis_scores",
            "assignment_id",
            $assignment->id->value,
            ["earned_hundredths", "excused", "version"],
            $condition,
        );
        foreach ($rows as &$row) {
            $row["earned_hundredths"] =
            $row["earned_hundredths"] === null ? null : (int) $row["earned_hundredths"];
            $row["excused"] = (bool) $row["excused"];
            $row["version"] = (int) $row["version"];
        }
        unset($row);
        return $rows;
    }

    public function attendanceRoster(
        EntityId $section,
        string $on,
        string $search,
        string $state,
        ?EntityId $after,
        int $limit,
    ): array {
        $q = $this->sql->quote(...);
        $condition = match ($state) {
            "all" => "1 = 1",
            "unmarked" => "m." . $q("status") . " IS NULL",
            default => "m." . $q("status") . " = ?",
        };
        $rows = $this->worksheetRoster(
            $section,
            $search,
            $after,
            $limit,
            "sis_attendance",
            "held_on",
            $on,
            ["id", "held_on", "status", "version"],
            $condition,
            in_array($state, ["all", "unmarked"], true) ? [] : [$state],
        );
        foreach ($rows as &$row) {
            $row["version"] = $row["version"] === null ? null : (int) $row["version"];
        }
        unset($row);
        return $rows;
    }

    /** Repository-owned SQL only; both teaching worksheets share the same bounded identity query.
     * @param list<string> $fields
     * @param list<string> $filterParameters
     * @return list<array<string,mixed>>
     */
    private function worksheetRoster(
        EntityId $section,
        string $search,
        ?EntityId $after,
        int $limit,
        string $table,
        string $joinField,
        string $joinValue,
        array $fields,
        string $condition,
        array $filterParameters = [],
    ): array {
        $q = $this->sql->quote(...);
        $col = static fn(string $alias, string $field): string => $alias . "." . $q($field);
        $parameters = [$joinValue, $section->value];
        $where = [$col("e", "section_id") . " = ?", $col("e", "status") . " IN ('A', 'N')"];
        if ($after !== null) {
            $where[] = $col("e", "id") . " < ?";
            $parameters[] = $after->value;
        }
        $where[] = $condition;
        array_push($parameters, ...$filterParameters);
        foreach (preg_split("/\s+/u", mb_strtolower($search), flags: PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = "%" . strtr($word, ["!" => "!!", "%" => "!%", "_" => "!_"]) . "%";
            $searchFields = [
                $col("p", "given_name"),
                $col("p", "family_name"),
                $col("i", "institutional_id"),
            ];
            $where[] =
            "(" .
            implode(
                " OR ",
                array_map(
                    static fn(string $field): string => "LOWER(" . $field . ") LIKE ? ESCAPE '!'",
                    $searchFields,
                ),
            ) .
            ")";
            array_push($parameters, $pattern, $pattern, $pattern);
        }
        $rows = $this->sql->fetch(
            "SELECT " .
                $col("e", "id") .
                " AS " .
                $q("enrollment_id") .
                ", " .
                $col("e", "student_id") .
                ", " .
                $col("p", "given_name") .
                ", " .
                $col("p", "family_name") .
                ", " .
                $col("i", "institutional_id") .
                ", " .
                implode(", ", array_map(static fn(string $field): string => $col("m", $field), $fields)) .
                " FROM " .
                $q("sis_enrollments") .
                " e JOIN " .
                $q("sis_students") .
                " s ON " .
                $col("s", "id") .
                " = " .
                $col("e", "student_id") .
                " JOIN " .
                $q("sis_persons") .
                " p ON " .
                $col("p", "id") .
                " = " .
                $col("s", "person_id") .
                " JOIN " .
                $q("sis_person_identifiers") .
                " i ON " .
                $col("i", "person_id") .
                " = " .
                $col("p", "id") .
                " LEFT JOIN " .
                $q($table) .
                " m ON " .
                $col("m", "student_id") .
                " = " .
                $col("e", "student_id") .
                ($table === "sis_attendance"
                    ? " AND " . $col("m", "section_id") . " = " . $col("e", "section_id")
                    : "") .
                " AND " .
                $col("m", $joinField) .
                " = ? WHERE " .
                implode(" AND ", $where) .
                " ORDER BY " .
                $col("e", "id") .
                " DESC",
            $parameters,
            $limit,
        );
        return $rows;
    }

    public function attendanceRecord(EntityId $section, EntityId $student, string $on): ?array
    {
        return $this->sql->rows(
            "sis_attendance",
            [
                "section_id" => $section->value,
                "student_id" => $student->value,
                "held_on" => $on,
            ],
            1,
        )[0] ?? null;
    }

    public function attendancePage(EntityId $section, EntityId $student, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                ", " .
                $q("held_on") .
                ", " .
                $q("status") .
                ", " .
                $q("version") .
                " FROM " .
                $q("sis_attendance") .
                " WHERE " .
                $q("section_id") .
                " = ? AND " .
                $q("student_id") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            $after === null
                ? [$section->value, $student->value]
                : [$section->value, $student->value, $after->value],
            $limit,
        );
    }

    public function attendanceCounts(EntityId $section, EntityId $student): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT " .
                $q("status") .
                ", COUNT(*) AS " .
                $q("total") .
                " FROM " .
                $q("sis_attendance") .
                " WHERE " .
                $q("section_id") .
                " = ? AND " .
                $q("student_id") .
                " = ? GROUP BY " .
                $q("status"),
            [$section->value, $student->value],
        );
        $counts = ["present" => 0, "absent" => 0, "late" => 0, "excused" => 0];
        foreach ($rows as $row) {
            $counts[$row["status"]] = (int) $row["total"];
        }

        return $counts;
    }

    public function scoreRecord(EntityId $assignment, EntityId $student): ?array
    {
        $row =
        $this->sql->rows(
            "sis_scores",
            ["assignment_id" => $assignment->value, "student_id" => $student->value],
            1,
        )[0] ?? null;
        return $row === null
        ? null
        : [
                "earned_hundredths" =>
                    $row["earned_hundredths"] === null ? null : (int) $row["earned_hundredths"],
                "version" => (int) $row["version"],
                "excused" => (bool) $row["excused"],
            ];
    }

    public function score(AssignmentScore $score, int $expectedVersion): void
    {
        $criteria = [
            "assignment_id" => $score->assignmentId->value,
            "student_id" => $score->studentId->value,
        ];
        $existing = $this->sql->rows("sis_scores", $criteria);
        if ($existing === [] && $expectedVersion === 0) {
            $this->sql->insert(
                "sis_scores",
                ["id" => EntityId::generate()->value] +
                    $criteria + [
                        "earned_hundredths" => $score->earnedHundredths,
                        "excused" => (int) $score->excused,
                        "version" => 1,
                    ],
            );
        } elseif (
            $this->sql->update(
                "sis_scores",
                [
                    "earned_hundredths" => $score->earnedHundredths,
                    "excused" => (int) $score->excused,
                    "version" => $expectedVersion + 1,
                ],
                $criteria + ["version" => $expectedVersion],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The assignment score changed.");
        }
    }

    public function attendance(
        EntityId $section,
        EntityId $student,
        string $on,
        string $status,
        int $expectedVersion,
    ): void {
        $criteria = [
            "section_id" => $section->value,
            "student_id" => $student->value,
            "held_on" => $on,
        ];
        if ($expectedVersion === 0) {
            $this->sql->insert(
                "sis_attendance",
                ["id" => EntityId::generate()->value] + $criteria + ["status" => $status, "version" => 1],
            );
        } elseif (
            $this->sql->update(
                "sis_attendance",
                ["status" => $status, "version" => $expectedVersion + 1],
                $criteria + ["version" => $expectedVersion],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The attendance mark changed.");
        }
    }

    public function scores(
        EntityId $student,
        EntityId $section,
        ?EntityId $after = null,
        ?int $limit = null,
    ): array {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT a." .
                $q("id") .
                " AS " .
                $q("assignment_id") .
                ", a." .
                $q("title") .
                ", a." .
                $q("possible_hundredths") .
                ", a." .
                $q("bonus_hundredths") .
                ", a." .
                $q("due_on") .
                ", s." .
                $q("earned_hundredths") .
                ", s." .
                $q("excused") .
                ", s." .
                $q("version") .
                " FROM " .
                $q("sis_assignments") .
                " a LEFT JOIN " .
                $q("sis_scores") .
                " s ON s." .
                $q("assignment_id") .
                " = a." .
                $q("id") .
                " AND s." .
                $q("student_id") .
                " = ?" .
                " WHERE a." .
                $q("section_id") .
                " = ?" .
                ($after === null ? "" : " AND a." . $q("id") . " > ?") .
                " ORDER BY a." .
                $q("id"),
            $after === null
                ? [$student->value, $section->value]
                : [$student->value, $section->value, $after->value],
            $limit,
        );
        foreach ($rows as &$row) {
            $row["possible_hundredths"] = (int) $row["possible_hundredths"];
            $row["bonus_hundredths"] = (int) $row["bonus_hundredths"];
            $row["score_limit_hundredths"] = $row["possible_hundredths"] + $row["bonus_hundredths"];
            $row["earned_hundredths"] =
            $row["earned_hundredths"] === null ? null : (int) $row["earned_hundredths"];
            $row["version"] = (int) ($row["version"] ?? 0);
            $row["excused"] = (bool) ($row["excused"] ?? false);
        }
        unset($row);

        return $rows;
    }
}
