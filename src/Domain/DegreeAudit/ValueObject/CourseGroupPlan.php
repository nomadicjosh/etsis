<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use InvalidArgumentException;

/** Catalog planning figures, independent of student awards and completion decisions. */
final readonly class CourseGroupPlan
{
    /** @param list<string> $warnings */
    private function __construct(
        public int $courseCount,
        public int $catalogCreditsMilli,
        public int $ruleCount,
        public array $warnings,
    ) {
    }

    /** @param array<string, array<string,mixed>> $courses Resolved retained publications, keyed by course identity. */
    public static function forDefinition(
        CourseGroupDefinition|CourseGroupDefinitionV2 $group,
        array $courses,
    ): self {
        $definition = $group->definition;
        $credits = 0;
        foreach ($definition["courses"] as $reference) {
            $courseCredits = $courses[$reference["course_id"]]["credits_milli"] ?? null;
            if (!is_int($courseCredits) || $courseCredits < 0 || $courseCredits > 999999) {
                throw new InvalidArgumentException(
                    "Resolve every listed course publication before planning this group.",
                );
            }
            $credits += $courseCredits;
        }
        $count = count($definition["courses"]);
        $warnings = [];
        if ($count === 0) {
            $warnings[] = "Add published courses to plan this group.";
        } elseif (($definition["requirement_type"] ?? "credits") === "courses") {
            if ($definition["min_courses"] > $count) {
                $warnings[] = "The required course count exceeds the number of distinct listed courses.";
            }
        } elseif ($definition["min_credits_milli"] > $credits) {
            $warnings[] =
            "The required credits exceed the catalog credits of the listed courses. Review this total.";
        }
        return new self($count, $credits, count($definition["rules"]), $warnings);
    }

    /** @return array{course_count:int, catalog_credits_milli:int, rule_count:int, warnings:list<string>} */
    public function describe(): array
    {
        return [
            "course_count" => $this->courseCount,
            "catalog_credits_milli" => $this->catalogCreditsMilli,
            "rule_count" => $this->ruleCount,
            "warnings" => $this->warnings,
        ];
    }
}
