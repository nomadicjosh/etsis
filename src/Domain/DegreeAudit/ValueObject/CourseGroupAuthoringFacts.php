<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use InvalidArgumentException;

/** Allowlisted authoring facts shared by group and degree previews. No database identifiers survive. */
final readonly class CourseGroupAuthoringFacts
{
    /** @var array<string,mixed> */
    private array $facts;

    /**
     * @param array<string,array<string,mixed>> $courses Exact selected publications, keyed by course identity.
     * @param array<string,int> $degreePositions Optional request-local identities for cross-group comparisons.
     */
    public function __construct(
        #[\SensitiveParameter] CourseGroupDefinition|CourseGroupDefinitionV2 $definition,
        #[\SensitiveParameter] array $courses,
        #[\SensitiveParameter] array $degreePositions = [],
    ) {
        $d = $definition->definition;
        if ($definition instanceof CourseGroupDefinitionV2) {
            $facts = array_intersect_key(
                $d,
                array_flip([
                    "name",
                    "requirement_type",
                    "min_credits_milli",
                    "min_courses",
                    "min_course_grade_points_milli",
                    "max_course_grade_points_milli",
                ]),
            );
            $facts["program_grading_context_selected"] = $d["grade_scale_program_id"] !== null;
        } else {
            // Earlier average-group GPA is a different rule, never a per-course floor.
            $facts = [
                "name" => $d["name"],
                "requirement_type" => "credits",
                "min_credits_milli" => $d["min_credits_milli"],
                "legacy_average_group_gpa_milli" => $d["min_gpa_milli"],
            ];
        }
        $facts["courses"] = [];
        $positions = [];
        foreach ($d["courses"] as $index => $reference) {
            $id = $reference["course_id"];
            $course = $courses[$id] ?? null;
            if (
                !is_array($course) ||
                !is_string($course["code"] ?? null) ||
                !is_string($course["title"] ?? null) ||
                !is_int($course["credits_milli"] ?? null)
            ) {
                throw new InvalidArgumentException(
                    "Resolve each selected course publication before requesting advice.",
                );
            }
            $position = $index + 1;
            $positions[$id] = $position;
            $value = [
                "position" => $position,
                "code" => $course["code"],
                "title" => $course["title"],
                "credits_milli" => $course["credits_milli"],
            ];
            if ($degreePositions !== []) {
                $shared = $degreePositions[$id] ?? null;
                if (!is_int($shared) || $shared < 1) {
                    throw new InvalidArgumentException("Use a complete degree course-position map.");
                }
                $value["degree_course_position"] = $shared;
            }
            $facts["courses"][] = $value;
        }
        $facts["rules"] = [];
        foreach ($d["rules"] as $rule) {
            $facts["rules"][] = [
                "mode" => $rule["mode"],
                "minimum_courses" => $rule["minimum_courses"],
                "course_positions" => array_map(
                    static fn(string $id): int => $positions[$id],
                    $rule["course_ids"],
                ),
            ] + ($rule["mode"] === "credits" ? ["minimum_credits_milli" => $rule["minimum_credits_milli"]] : [])
            + (isset($rule['eligibility']) ? [
                    'saved_rule_selected' => true, 'saved_rule_requires_student_work' => true,
                ] : []);
        }
        $this->facts = $facts;
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return $this->facts;
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["facts" => "[private authoring facts]"];
    }
}
