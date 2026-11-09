<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Gradebook\GradeDefinition;
use InvalidArgumentException;

/** Group quantity and per-course grade eligibility; no average-GPA reinterpretation. */
final readonly class CourseGroupDefinitionV2
{
    /** @param array<string,mixed> $definition */
    public function __construct(public array $definition)
    {
        $keys = [
            "name",
            "requirement_type",
            "min_credits_milli",
            "min_courses",
            "min_course_grade_points_milli",
            "max_course_grade_points_milli",
            "grade_scale_program_id",
            "courses",
            "rules",
        ];
        if (count($definition) !== count($keys) || array_diff(array_keys($definition), $keys) !== []) {
            self::invalid();
        }
        if ($definition["grade_scale_program_id"] !== null) {
            if (!is_string($definition["grade_scale_program_id"])) {
                self::invalid();
            }
            new \Domain\Shared\ValueObject\EntityId($definition["grade_scale_program_id"]);
        }
        new CourseGroupDefinition([
            "name" => $definition["name"],
            "min_credits_milli" => $definition["min_credits_milli"],
            "min_gpa_milli" => null,
            "courses" => $definition["courses"],
            "rules" => $definition["rules"],
        ]);
        if (
            !in_array($definition["requirement_type"], ["credits", "courses"], true) ||
            !is_int($definition["min_courses"]) ||
            $definition["min_courses"] < 0 ||
            $definition["min_courses"] > 200 ||
            ($definition["requirement_type"] === "credits" && $definition["min_courses"] !== 0) ||
            ($definition["requirement_type"] === "courses" && $definition["min_credits_milli"] !== 0) ||
            !is_int($definition["max_course_grade_points_milli"]) ||
            $definition["max_course_grade_points_milli"] < 0 ||
            $definition["max_course_grade_points_milli"] > 10000 ||
            ($definition["min_course_grade_points_milli"] !== null &&
                (!is_int($definition["min_course_grade_points_milli"]) ||
                    $definition["min_course_grade_points_milli"] < 0 ||
                    $definition["min_course_grade_points_milli"] >
                        $definition["max_course_grade_points_milli"]))
        ) {
            self::invalid();
        }
        if (strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 49152) {
            self::invalid();
        }
    }
    public static function blank(string $name, int $maximumPointsMilli = 4000): self
    {
        return new self([
            "name" => $name,
            "requirement_type" => "credits",
            "min_credits_milli" => 0,
            "min_courses" => 0,
            "min_course_grade_points_milli" => null,
            "max_course_grade_points_milli" => $maximumPointsMilli,
            "grade_scale_program_id" => null,
            "courses" => [],
            "rules" => [],
        ]);
    }
    /** @param array<string,mixed> $definition */
    public static function draft(array $definition): self|CourseGroupDefinition
    {
        return array_key_exists("requirement_type", $definition)
        ? new self($definition)
        : new CourseGroupDefinition($definition);
    }
    public function assertPublishable(): void
    {
        $d = $this->definition;
        if (
            $d["rules"] === [] ||
            ($d["requirement_type"] === "credits" && $d["min_credits_milli"] < 1) ||
            ($d["requirement_type"] === "courses" &&
                ($d["min_courses"] < 1 || $d["min_courses"] > count($d["courses"])))
        ) {
            throw new InvalidArgumentException(
                "Choose a positive group credit/course requirement with enough listed courses.",
            );
        }
        foreach ($d["rules"] as $r) {
            if ($r["course_ids"] === []) {
                throw new InvalidArgumentException("Every published course list needs at least one course.");
            }
        }
    }
    /** Eligibility gate only. The future evaluator supplies the retained grade definition, not a mutable lookup. */
    public function permitsCompletedGrade(GradeDefinition $grade): bool
    {
        return $grade->passes &&
        ($this->definition["min_course_grade_points_milli"] === null ||
        $grade->pointsMilli >= $this->definition["min_course_grade_points_milli"]);
    }
    public function hash(): string
    {
        return hash("sha256", json_encode($this->definition, JSON_THROW_ON_ERROR));
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "Choose credits or courses for the group, with an optional per-course grade-point minimum.",
        );
    }
}
