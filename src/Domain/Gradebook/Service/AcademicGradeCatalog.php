<?php

declare(strict_types=1);

namespace Domain\Gradebook\Service;

use Domain\Gradebook\Repository\AcademicGradeRepository;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Internal grade policy selection. Call only after authorizing the student/office workflow. */
final readonly class AcademicGradeCatalog
{
    public function __construct(
        private AcademicGradeRepository $repository,
        private GradingScaleService $scales,
    ) {
    }
    /** @return list<array<string,mixed>> */
    public function choices(
        ?EntityId $student = null,
        ?EntityId $retainedGrade = null,
        ?EntityId $assignment = null,
    ): array {
        if ($retainedGrade !== null) {
            $grade = $this->repository->grade($retainedGrade);
            if ($grade["scale_version_id"] !== null) {
                return $this->publicationGrades($grade);
            }
            // An older recorded grade keeps its original, explicitly unknown policy provenance.
            return $this->legacy();
        }
        $program = null;
        if ($student !== null) {
            $pursuits = $this->repository->pursuits($student);
            if ($pursuits === [] && $this->repository->hasPursuit($student)) {
                self::invalid(
                    "Review this student’s closed program pursuits " .
                        "before choosing a policy for an ungraded academic record.",
                );
            }
            if (count($pursuits) > 20) {
                self::invalid("Review the student’s program pursuits before selecting a grade.");
            }
            $unique = [];
            foreach ($pursuits as $p) {
                if ($assignment !== null && $p["id"] !== $assignment->value) {
                    continue;
                }
                $scale = $this->scales->resolve(new EntityId($p["program_id"]));
                $key = $scale["source"] . ":" . ($scale["version_id"] ?? "unconfigured");
                $unique[$key] = new EntityId($p["program_id"]);
            }
            if ($assignment !== null && $unique === []) {
                self::invalid("Choose an active program pursuit belonging to this student.");
            }
            if (count($unique) > 1) {
                throw new \Domain\Gradebook\Exception\GradingContextRequiredException(
                    "This student has programs with different grading scales. Choose the applicable program pursuit.",
                );
            }
            $program = $unique === [] ? null : array_values($unique)[0];
        }
        $scale = $this->scales->resolve($program);
        if ($scale["version_id"] === null) {
            return $this->legacy();
        }
        return $this->scales->publication(
            $scale["program_id"] === null ? null : new EntityId($scale["program_id"]),
            new EntityId($scale["version_id"]),
        )["grades"];
    }
    /** @return array<string,mixed> */
    public function byCode(
        string $code,
        ?EntityId $student = null,
        ?EntityId $retainedGrade = null,
        ?EntityId $assignment = null,
    ): array {
        $found = [];
        foreach ($this->choices($student, $retainedGrade, $assignment) as $r) {
            if ($r["code"] === $code) {
                $found[] = $r;
            }
        }
        if (count($found) !== 1) {
            self::invalid("Choose a grade defined in the applicable published scale.");
        }
        return $found[0];
    }
    /** @return array<string,mixed> */
    public function choose(
        EntityId $grade,
        EntityId $student,
        ?EntityId $retainedGrade = null,
        ?EntityId $assignment = null,
    ): array {
        foreach ($this->choices($student, $retainedGrade, $assignment) as $row) {
            if ($row["id"] === $grade->value) {
                return $row;
            }
        }
        self::invalid(
            "The selected grade is outside this academic record’s grading scale. Reload the grade choices.",
        );
    }
    /** @return list<array<string,mixed>> */
    public function pursuits(EntityId $student): array
    {
        return $this->repository->pursuits($student);
    }
    /** @param array<string,mixed> $grade
     * @return list<array<string,mixed>>
     */
    private function publicationGrades(array $grade): array
    {
        $program =
        $grade["scale_id"] === GradingScaleService::GLOBAL_ID ? null : new EntityId($grade["scale_id"]);
        return $this->scales->publication($program, new EntityId($grade["scale_version_id"]))["grades"];
    }
    /** @return list<array<string,mixed>> */
    private function legacy(): array
    {
        $rows = $this->repository->legacyGrades();
        if (count($rows) > 200) {
            self::invalid("Review earlier grade definitions before continuing.");
        }
        foreach ($rows as &$r) {
            foreach (["points_milli", "passes", "counts_gpa"] as $key) {
                $r[$key] = (int) $r[$key];
            }
        }
        unset($r);
        return $rows;
    }
    private static function invalid(string $message): never
    {
        throw new ValidationException([["field" => "grade_id", "message" => $message]]);
    }
}
